<?php

namespace ApnTalk\LaravelFreeswitchEsl\Tests\Unit\Worker;

use ApnTalk\LaravelFreeswitchEsl\Contracts\ConnectionFactoryInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\ConnectionResolverInterface;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\ConnectionContext;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\PbxNode;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\WorkerStatus;
use ApnTalk\LaravelFreeswitchEsl\Exceptions\WorkerException;
use ApnTalk\LaravelFreeswitchEsl\Contracts\RuntimeHandoffInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\RuntimeRunnerInterface;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslCoreConnectionHandle;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslCorePipelineFactory;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslCoreCommandFactory;
use ApnTalk\LaravelFreeswitchEsl\Integration\NonLiveRuntimeRunner;
use ApnTalk\LaravelFreeswitchEsl\Worker\WorkerRuntime;
use Apntalk\EslCore\Transport\InMemoryTransport;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class WorkerRuntimeTest extends TestCase
{
    public function test_boot_retains_resolved_context_with_session_id(): void
    {
        $runtime = $this->makeRuntime();

        $runtime->boot();
        $context = $runtime->resolvedContext();

        $this->assertNotNull($context);
        $this->assertSame('test-node', $context->pbxNodeSlug);
        $this->assertNotNull($context->workerSessionId);
    }

    public function test_boot_creates_and_retains_connection_handle(): void
    {
        $runtime = $this->makeRuntime();

        $runtime->boot();
        $handle = $runtime->connectionHandle();

        $this->assertInstanceOf(RuntimeHandoffInterface::class, $handle);
        $this->assertInstanceOf(EslCoreConnectionHandle::class, $handle);
        $this->assertSame($runtime->resolvedContext(), $handle->context());
        $this->assertSame('tcp://127.0.0.1:8021', $handle->endpoint());
    }

    public function test_status_before_boot_reports_unprepared_handoff_state(): void
    {
        $runtime = $this->makeRuntime();
        $status = $runtime->status();

        $this->assertSame(WorkerStatus::STATE_BOOTING, $status->state);
        $this->assertFalse($status->meta['context_resolved']);
        $this->assertFalse($status->meta['connection_handoff_prepared']);
        $this->assertFalse($status->meta['runtime_adapter_ready']);
        $this->assertNull($status->meta['handoff_endpoint']);
        $this->assertFalse($status->meta['runtime_runner_invoked']);
        $this->assertSame(RuntimeRunnerInterface::class, $status->meta['runtime_runner_contract']);
        $this->assertSame(NonLiveRuntimeRunner::class, $status->meta['runtime_runner_class']);
        $this->assertFalse($status->meta['runtime_loop_active']);
        $this->assertFalse($status->isHandoffPrepared());
        $this->assertFalse($status->isRuntimeRunnerInvoked());
        $this->assertFalse($status->isRuntimeLoopActive());
    }

    public function test_status_after_boot_reports_prepared_handoff_state(): void
    {
        $runtime = $this->makeRuntime();

        $runtime->boot();
        $status = $runtime->status();

        $this->assertSame(WorkerStatus::STATE_RUNNING, $status->state);
        $this->assertTrue($status->meta['context_resolved']);
        $this->assertTrue($status->meta['connection_handoff_prepared']);
        $this->assertTrue($status->meta['runtime_adapter_ready']);
        $this->assertSame('tcp://127.0.0.1:8021', $status->meta['handoff_endpoint']);
        $this->assertSame(RuntimeHandoffInterface::class, $status->meta['runtime_handoff_contract']);
        $this->assertSame(EslCoreConnectionHandle::class, $status->meta['runtime_handoff_class']);
        $this->assertFalse($status->meta['runtime_runner_invoked']);
        $this->assertSame(RuntimeRunnerInterface::class, $status->meta['runtime_runner_contract']);
        $this->assertSame(NonLiveRuntimeRunner::class, $status->meta['runtime_runner_class']);
        $this->assertFalse($status->meta['runtime_loop_active']);
        $this->assertTrue($status->isHandoffPrepared());
        $this->assertFalse($status->isRuntimeRunnerInvoked());
        $this->assertFalse($status->isRuntimeLoopActive());
    }

    public function test_run_before_boot_throws_when_handoff_state_missing(): void
    {
        $runtime = $this->makeRuntime();

        $this->expectException(WorkerException::class);
        $this->expectExceptionMessage('runtime handoff state is incomplete');

        $runtime->run();
    }

    public function test_runtime_handoff_exposes_adapter_facing_contract_after_boot(): void
    {
        $runtime = $this->makeRuntime();

        $runtime->boot();
        $handoff = $runtime->runtimeHandoff();

        $this->assertInstanceOf(RuntimeHandoffInterface::class, $handoff);
        $this->assertSame($runtime->connectionHandle(), $handoff);
    }

    public function test_run_after_boot_keeps_connection_handle_available(): void
    {
        $runtime = $this->makeRuntime();

        $runtime->boot();
        $handle = $runtime->connectionHandle();
        $runtime->run();

        $this->assertSame($handle, $runtime->connectionHandle());
    }

    public function test_run_after_boot_marks_runtime_runner_invoked_without_marking_runtime_active(): void
    {
        $runtime = $this->makeRuntime();

        $runtime->boot();
        $runtime->run();
        $status = $runtime->status();

        $this->assertTrue($status->meta['runtime_runner_invoked']);
        $this->assertTrue($status->isRuntimeRunnerInvoked());
        $this->assertFalse($status->meta['runtime_loop_active']);
        $this->assertFalse($status->isRuntimeLoopActive());
    }

    private function makeRuntime(): WorkerRuntime
    {
        $node = new PbxNode(
            id: 1,
            providerId: 1,
            providerCode: 'freeswitch',
            name: 'Test Node',
            slug: 'test-node',
            host: '127.0.0.1',
            port: 8021,
            username: '',
            passwordSecretRef: 'secret',
            transport: 'tcp',
            isActive: true,
        );

        $resolver = new class implements ConnectionResolverInterface {
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
                    pbxNodeSlug: 'test-node',
                    providerCode: 'freeswitch',
                    host: '127.0.0.1',
                    port: 8021,
                    username: '',
                    resolvedPassword: 'ClueCon',
                    transport: 'tcp',
                    connectionProfileId: null,
                    connectionProfileName: 'default',
                );
            }
        };

        $connectionFactory = new class implements ConnectionFactoryInterface {
            public function create(ConnectionContext $context): EslCoreConnectionHandle
            {
                $commandFactory = new EslCoreCommandFactory();

                return new EslCoreConnectionHandle(
                    context: $context,
                    pipeline: (new EslCorePipelineFactory())->createPipeline(),
                    openingSequence: $commandFactory->buildOpeningSequence($context),
                    closingSequence: $commandFactory->buildClosingSequence(),
                    transportOpener: fn () => new InMemoryTransport(),
                );
            }
        };

        return new WorkerRuntime(
            workerName: 'test-worker',
            node: $node,
            connectionResolver: $resolver,
            connectionFactory: $connectionFactory,
            runtimeRunner: new NonLiveRuntimeRunner(),
            logger: new NullLogger(),
        );
    }
}
