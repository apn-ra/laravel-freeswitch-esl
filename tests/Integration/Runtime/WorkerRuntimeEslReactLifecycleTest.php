<?php

namespace ApnTalk\LaravelFreeswitchEsl\Tests\Integration\Runtime;

use Apntalk\EslCore\Transport\InMemoryTransport;
use Apntalk\EslReact\AsyncEslRuntime;
use ApnTalk\LaravelFreeswitchEsl\Contracts\ConnectionFactoryInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\ConnectionResolverInterface;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\ConnectionContext;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\HealthSnapshot;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\PbxNode;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\WorkerStatus;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslCoreCommandFactory;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslCoreConnectionHandle;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslCorePipelineFactory;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslReactRuntimeBootstrapInputFactory;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslReactRuntimeRunnerAdapter;
use ApnTalk\LaravelFreeswitchEsl\Tests\Support\AsyncLoopTestCase;
use ApnTalk\LaravelFreeswitchEsl\Tests\Support\Harness\ScriptedFakeEslServer;
use ApnTalk\LaravelFreeswitchEsl\Worker\WorkerRuntime;
use Psr\Log\NullLogger;
use React\Socket\Connector;

class WorkerRuntimeEslReactLifecycleTest extends AsyncLoopTestCase
{
    public function test_worker_runtime_observes_live_connect_disconnect_reconnect_and_drain_posture(): void
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $this->seedHandshakeHandlers($server);
        $this->seedHandshakeHandlers($server);

        $runtime = $this->makeRuntime($server->port());

        $runtime->boot();
        $runtime->run();

        $this->waitUntil(function () use ($runtime, $server): bool {
            $status = $runtime->status();

            return $status->meta['runtime_status_phase'] === 'active'
                && $server->receivedCommandsByConnection() !== [];
        }, 1.5);

        $this->assertSame([
            'auth ClueCon',
            'event plain CHANNEL_CREATE',
        ], $server->receivedCommandsByConnection()[0]);

        $server->emitPlainEvent([
            'Event-Name' => 'CHANNEL_CREATE',
            'Unique-ID' => 'uuid-runtime-harness',
        ]);
        $this->runLoopFor(0.02);

        $server->closeActiveConnection();

        $this->waitUntil(function () use ($runtime): bool {
            $status = $runtime->status();

            return $status->state === WorkerStatus::STATE_RECONNECTING
                || $status->meta['runtime_status_phase'] === 'reconnecting';
        }, 1.5);

        $this->waitUntil(function () use ($runtime, $server): bool {
            return $server->connectionCount() === 2
                && $runtime->status()->meta['runtime_status_phase'] === 'active';
        }, 2.0);

        $status = $runtime->status();

        $this->assertTrue($status->meta['runtime_feedback_observed']);
        $this->assertTrue($status->meta['runtime_push_lifecycle_observed']);
        $this->assertTrue($status->meta['runtime_loop_active']);
        $this->assertNotNull($status->meta['runtime_last_successful_connect_at']);
        $this->assertNotNull($status->meta['runtime_last_disconnect_at']);

        $health = HealthSnapshot::fromWorkerStatus($runtime->node(), $status, 'node');

        $this->assertSame(HealthSnapshot::STATUS_HEALTHY, $health->status);
        $this->assertTrue($health->meta['live_runtime_linked']);
        $this->assertSame('active', $health->meta['runtime_status_phase']);

        $runtime->beginInflightWork();
        $runtime->drain();

        $drainingStatus = $runtime->status();

        $this->assertSame(WorkerStatus::STATE_DRAINING, $drainingStatus->state);
        $this->assertTrue($drainingStatus->meta['backpressure_active']);
        $this->assertSame('draining', $drainingStatus->meta['backpressure_reason']);
        $this->assertSame(1, $drainingStatus->meta['drain_waiting_on_inflight']);

        $runtime->completeInflightWork();

        $this->assertTrue($runtime->status()->meta['drain_completed']);

        $server->close();
        $this->runLoopFor(0.05);
    }

    private function seedHandshakeHandlers(ScriptedFakeEslServer $server): void
    {
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });

        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('event plain CHANNEL_CREATE', $command);
            $server->writeCommandReply($connection, '+OK event listener enabled plain');
        });
    }

    private function makeRuntime(int $port): WorkerRuntime
    {
        $node = new PbxNode(
            id: 1,
            providerId: 1,
            providerCode: 'freeswitch',
            name: 'Runtime Node',
            slug: 'runtime-node',
            host: '127.0.0.1',
            port: $port,
            username: '',
            passwordSecretRef: 'secret',
            transport: 'tcp',
            isActive: true,
        );

        $resolver = new class($port) implements ConnectionResolverInterface
        {
            public function __construct(
                private readonly int $port,
            ) {}

            public function resolveForNode(int $pbxNodeId): ConnectionContext
            {
                return $this->context();
            }

            public function resolveForSlug(string $slug): ConnectionContext
            {
                return $this->context();
            }

            public function resolveForPbxNode(PbxNode $node): ConnectionContext
            {
                return $this->context();
            }

            private function context(): ConnectionContext
            {
                return new ConnectionContext(
                    pbxNodeId: 1,
                    pbxNodeSlug: 'runtime-node',
                    providerCode: 'freeswitch',
                    host: '127.0.0.1',
                    port: $this->port,
                    username: '',
                    resolvedPassword: 'ClueCon',
                    transport: 'tcp',
                    connectionProfileId: null,
                    connectionProfileName: 'default',
                    driverParameters: [
                        'subscription' => [
                            'event_names' => ['CHANNEL_CREATE'],
                        ],
                    ],
                );
            }
        };

        $connectionFactory = new class implements ConnectionFactoryInterface
        {
            public function create(ConnectionContext $context): EslCoreConnectionHandle
            {
                $commandFactory = new EslCoreCommandFactory;

                return new EslCoreConnectionHandle(
                    context: $context,
                    pipeline: (new EslCorePipelineFactory)->createPipeline(),
                    openingSequence: $commandFactory->buildOpeningSequence($context),
                    closingSequence: $commandFactory->buildClosingSequence(),
                    transportOpener: fn () => new InMemoryTransport,
                );
            }
        };

        $runtimeRunner = new EslReactRuntimeRunnerAdapter(
            runner: AsyncEslRuntime::runner(),
            inputFactory: new EslReactRuntimeBootstrapInputFactory(
                connector: new Connector([], $this->loop),
            ),
        );

        return new WorkerRuntime(
            workerName: 'integration-worker',
            node: $node,
            connectionResolver: $resolver,
            connectionFactory: $connectionFactory,
            runtimeRunner: $runtimeRunner,
            logger: new NullLogger,
            drainTimeoutMilliseconds: 1000,
            maxInflight: 2,
            checkpointIntervalSeconds: 60,
        );
    }
}
