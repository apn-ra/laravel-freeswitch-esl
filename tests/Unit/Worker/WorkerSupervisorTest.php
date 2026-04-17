<?php

namespace ApnTalk\LaravelFreeswitchEsl\Tests\Unit\Worker;

use Apntalk\EslCore\Transport\InMemoryTransport;
use ApnTalk\LaravelFreeswitchEsl\Contracts\ConnectionFactoryInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\ConnectionResolverInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\RuntimeHandoffInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\RuntimeRunnerInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\WorkerAssignmentResolverInterface;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\ConnectionContext;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\PbxNode;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\WorkerStatus;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslCoreCommandFactory;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslCoreConnectionHandle;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslCorePipelineFactory;
use ApnTalk\LaravelFreeswitchEsl\Worker\WorkerSupervisor;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class WorkerSupervisorTest extends TestCase
{
    public function test_runtime_statuses_surface_prepared_handoff_state_per_node(): void
    {
        $node = $this->makeNode(1, 'node-a');
        $assignmentResolver = $this->createStub(WorkerAssignmentResolverInterface::class);
        $connectionResolver = new class implements ConnectionResolverInterface
        {
            public function resolveForNode(int $pbxNodeId): ConnectionContext
            {
                return $this->context('node-a');
            }

            public function resolveForSlug(string $slug): ConnectionContext
            {
                return $this->context($slug);
            }

            public function resolveForPbxNode(PbxNode $node): ConnectionContext
            {
                return $this->context($node->slug);
            }

            private function context(string $slug): ConnectionContext
            {
                return new ConnectionContext(
                    pbxNodeId: 1,
                    pbxNodeSlug: $slug,
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

        $runtimeRunner = new class implements RuntimeRunnerInterface
        {
            public function run(RuntimeHandoffInterface $handoff): void {}
        };

        $supervisor = new WorkerSupervisor(
            assignmentResolver: $assignmentResolver,
            connectionResolver: $connectionResolver,
            connectionFactory: $connectionFactory,
            runtimeRunner: $runtimeRunner,
            logger: new NullLogger,
        );

        $supervisor->runForNodes('worker-a', 'db-backed', [$node]);
        $statuses = $supervisor->runtimeStatuses();

        $this->assertCount(1, $statuses);
        $this->assertArrayHasKey('node-a', $statuses);
        $this->assertSame(WorkerStatus::STATE_RUNNING, $statuses['node-a']->state);
        $this->assertTrue($statuses['node-a']->meta['context_resolved']);
        $this->assertTrue($statuses['node-a']->meta['connection_handoff_prepared']);
        $this->assertTrue($statuses['node-a']->meta['runtime_adapter_ready']);
        $this->assertSame('tcp://127.0.0.1:8021', $statuses['node-a']->meta['handoff_endpoint']);
        $this->assertTrue($statuses['node-a']->meta['runtime_runner_invoked']);
        $this->assertSame(RuntimeRunnerInterface::class, $statuses['node-a']->meta['runtime_runner_contract']);
        $this->assertSame($runtimeRunner::class, $statuses['node-a']->meta['runtime_runner_class']);
        $this->assertFalse($statuses['node-a']->meta['runtime_loop_active']);
        $this->assertTrue($statuses['node-a']->isHandoffPrepared());
        $this->assertTrue($statuses['node-a']->isRuntimeRunnerInvoked());
    }

    public function test_runtime_handoffs_expose_adapter_ready_bundles_keyed_by_node_slug(): void
    {
        $node = $this->makeNode(1, 'node-a');
        $assignmentResolver = $this->createStub(WorkerAssignmentResolverInterface::class);
        $connectionResolver = new class implements ConnectionResolverInterface
        {
            public function resolveForNode(int $pbxNodeId): ConnectionContext
            {
                return $this->context('node-a');
            }

            public function resolveForSlug(string $slug): ConnectionContext
            {
                return $this->context($slug);
            }

            public function resolveForPbxNode(PbxNode $node): ConnectionContext
            {
                return $this->context($node->slug);
            }

            private function context(string $slug): ConnectionContext
            {
                return new ConnectionContext(
                    pbxNodeId: 1,
                    pbxNodeSlug: $slug,
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

        $runtimeRunner = new class implements RuntimeRunnerInterface
        {
            public function run(RuntimeHandoffInterface $handoff): void {}
        };

        $supervisor = new WorkerSupervisor(
            assignmentResolver: $assignmentResolver,
            connectionResolver: $connectionResolver,
            connectionFactory: $connectionFactory,
            runtimeRunner: $runtimeRunner,
            logger: new NullLogger,
        );

        $supervisor->runForNodes('worker-a', 'db-backed', [$node]);
        $handoffs = $supervisor->runtimeHandoffs();

        $this->assertArrayHasKey('node-a', $handoffs);
        $this->assertInstanceOf(RuntimeHandoffInterface::class, $handoffs['node-a']);
        $this->assertSame('node-a', $handoffs['node-a']->context()->pbxNodeSlug);
    }

    public function test_prepare_for_nodes_boots_runtimes_without_invoking_runner(): void
    {
        $node = $this->makeNode(1, 'node-a');
        $assignmentResolver = $this->createStub(WorkerAssignmentResolverInterface::class);
        $connectionResolver = new class implements ConnectionResolverInterface
        {
            public function resolveForNode(int $pbxNodeId): ConnectionContext
            {
                return $this->context('node-a');
            }

            public function resolveForSlug(string $slug): ConnectionContext
            {
                return $this->context($slug);
            }

            public function resolveForPbxNode(PbxNode $node): ConnectionContext
            {
                return $this->context($node->slug);
            }

            private function context(string $slug): ConnectionContext
            {
                return new ConnectionContext(
                    pbxNodeId: 1,
                    pbxNodeSlug: $slug,
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

        $runtimeRunner = new class implements RuntimeRunnerInterface
        {
            public int $runCalls = 0;

            public function run(RuntimeHandoffInterface $handoff): void
            {
                $this->runCalls++;
            }
        };

        $supervisor = new WorkerSupervisor(
            assignmentResolver: $assignmentResolver,
            connectionResolver: $connectionResolver,
            connectionFactory: $connectionFactory,
            runtimeRunner: $runtimeRunner,
            logger: new NullLogger,
        );

        $supervisor->prepareForNodes('worker-a', 'db-backed', [$node]);
        $statuses = $supervisor->runtimeStatuses();

        $this->assertCount(1, $statuses);
        $this->assertSame(0, $runtimeRunner->runCalls);
        $this->assertSame(WorkerStatus::STATE_RUNNING, $statuses['node-a']->state);
        $this->assertTrue($statuses['node-a']->meta['connection_handoff_prepared']);
        $this->assertFalse($statuses['node-a']->meta['runtime_runner_invoked']);
    }

    private function makeNode(int $id, string $slug): PbxNode
    {
        return new PbxNode(
            id: $id,
            providerId: 1,
            providerCode: 'freeswitch',
            name: strtoupper($slug),
            slug: $slug,
            host: '127.0.0.1',
            port: 8021,
            username: '',
            passwordSecretRef: 'secret',
            transport: 'tcp',
            isActive: true,
        );
    }
}
