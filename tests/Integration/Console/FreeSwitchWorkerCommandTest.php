<?php

namespace ApnTalk\LaravelFreeswitchEsl\Tests\Integration\Console;

use Apntalk\EslCore\Transport\InMemoryTransport;
use Apntalk\EslReplay\Artifact\CapturedArtifactEnvelope;
use Apntalk\EslReplay\Checkpoint\ReplayCheckpoint;
use Apntalk\EslReplay\Checkpoint\ReplayCheckpointCriteria;
use Apntalk\EslReplay\Checkpoint\ReplayCheckpointRepository;
use Apntalk\EslReplay\Contracts\ReplayArtifactStoreInterface;
use Apntalk\EslReplay\Contracts\ReplayCheckpointStoreInterface;
use Apntalk\EslReplay\Cursor\ReplayReadCursor;
use Apntalk\EslReplay\Read\ReplayReadCriteria;
use Apntalk\EslReplay\Storage\ReplayRecordId;
use Apntalk\EslReplay\Storage\StoredReplayRecord;
use ApnTalk\LaravelFreeswitchEsl\Console\Support\WorkerStatusReportBuilder;
use ApnTalk\LaravelFreeswitchEsl\Contracts\ConnectionFactoryInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\ConnectionResolverInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\HealthReporterInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\PbxRegistryInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\RuntimeHandoffInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\RuntimeRunnerFeedbackProviderInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\RuntimeRunnerInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\WorkerAssignmentResolverInterface;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\ConnectionContext;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\HealthSnapshot;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\PbxNode;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\RuntimeRunnerFeedback;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\WorkerAssignment;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\WorkerStatus;
use ApnTalk\LaravelFreeswitchEsl\Exceptions\PbxNotFoundException;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslCoreCommandFactory;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslCoreConnectionHandle;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslCorePipelineFactory;
use ApnTalk\LaravelFreeswitchEsl\Integration\NonLiveRuntimeRunner;
use ApnTalk\LaravelFreeswitchEsl\Integration\Replay\WorkerReplayCheckpointManager;
use ApnTalk\LaravelFreeswitchEsl\Tests\TestCase;
use Illuminate\Contracts\Console\Kernel;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class FreeSwitchWorkerCommandTest extends TestCase
{
    public function test_worker_command_is_registered_with_the_console_kernel(): void
    {
        /** @var Kernel $kernel */
        $kernel = $this->app->make(Kernel::class);

        $this->assertArrayHasKey('freeswitch:worker', $kernel->all());
    }

    public function test_worker_command_ephemeral_pbx_path_prepares_runtime_handoff(): void
    {
        $node = $this->makeNode(1, 'primary-fs');
        $registry = new class($node) implements PbxRegistryInterface
        {
            public int $findBySlugCalls = 0;

            public function __construct(private readonly PbxNode $node) {}

            public function findById(int $id): PbxNode
            {
                return $this->node;
            }

            public function findBySlug(string $slug): PbxNode
            {
                $this->findBySlugCalls++;

                if ($slug !== $this->node->slug) {
                    throw new PbxNotFoundException($slug);
                }

                return $this->node;
            }

            public function allActive(): array
            {
                return [$this->node];
            }

            public function allByCluster(string $cluster): array
            {
                return [$this->node];
            }

            public function allByTags(array $tags): array
            {
                return [$this->node];
            }

            public function allByProvider(string $providerCode): array
            {
                return [$this->node];
            }
        };

        $assignmentResolver = new class($node) implements WorkerAssignmentResolverInterface
        {
            public int $resolveNodesCalls = 0;

            public int $resolveForWorkerNameCalls = 0;

            public ?WorkerAssignment $lastAssignment = null;

            public function __construct(private readonly PbxNode $node) {}

            public function resolveNodes(WorkerAssignment $assignment): array
            {
                $this->resolveNodesCalls++;
                $this->lastAssignment = $assignment;

                return [$this->node];
            }

            public function resolveForWorkerName(string $workerName): array
            {
                $this->resolveForWorkerNameCalls++;

                return [];
            }
        };

        $connectionResolver = new class implements ConnectionResolverInterface
        {
            /** @var list<string> */
            public array $resolvedNodeSlugs = [];

            public function resolveForNode(int $pbxNodeId): ConnectionContext
            {
                return $this->makeContext('primary-fs');
            }

            public function resolveForSlug(string $slug): ConnectionContext
            {
                return $this->makeContext($slug);
            }

            public function resolveForPbxNode(PbxNode $node): ConnectionContext
            {
                $this->resolvedNodeSlugs[] = $node->slug;

                return $this->makeContext($node->slug);
            }

            private function makeContext(string $slug): ConnectionContext
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
            public int $createCalls = 0;

            /** @var list<ConnectionContext> */
            public array $contexts = [];

            public function create(ConnectionContext $context): EslCoreConnectionHandle
            {
                $this->createCalls++;
                $this->contexts[] = $context;

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

        $this->app->instance(PbxRegistryInterface::class, $registry);
        $this->app->instance(WorkerAssignmentResolverInterface::class, $assignmentResolver);
        $this->app->instance(ConnectionResolverInterface::class, $connectionResolver);
        $this->app->instance(ConnectionFactoryInterface::class, $connectionFactory);
        $this->app->instance(RuntimeRunnerInterface::class, $runtimeRunner);
        $this->app->instance(LoggerInterface::class, new NullLogger);

        $this->artisan('freeswitch:worker', [
            '--worker' => 'ingest-worker',
            '--pbx' => 'primary-fs',
        ])
            ->expectsOutputToContain('Starting worker [ingest-worker] in [node] mode')
            ->expectsOutputToContain('Prepared runtime handoff for 1/1 node(s); runtime runner invoked for 1/1 node(s); push lifecycle observed for 0/1 node(s); live runtime observed for 0/1 node(s).')
            ->expectsOutputToContain('Operator posture: metrics driver log; backpressure active on 0/1 node(s); draining on 0/1 node(s).')
            ->expectsOutputToContain('Replay-backed checkpoint/recovery posture reflects persisted replay artifacts only; it does not imply live socket or reconnect recovery.')
            ->expectsOutputToContain('- primary-fs: checkpoint disabled; prior checkpoint no; recovery hint disabled; anchors -; drain idle; backpressure idle (max inflight 100, rejected total 0); operator action none')
            ->assertExitCode(0);

        $this->assertSame(1, $registry->findBySlugCalls);
        $this->assertSame(1, $assignmentResolver->resolveNodesCalls);
        $this->assertSame(0, $assignmentResolver->resolveForWorkerNameCalls);
        $this->assertNotNull($assignmentResolver->lastAssignment);
        $this->assertSame('node', $assignmentResolver->lastAssignment->assignmentMode);
        $this->assertSame(1, $connectionFactory->createCalls);
        $this->assertCount(1, $connectionFactory->contexts);
        $this->assertSame('primary-fs', $connectionFactory->contexts[0]->pbxNodeSlug);
        $this->assertNotNull($connectionFactory->contexts[0]->workerSessionId);
        $this->assertSame(1, $runtimeRunner->runCalls);
    }

    public function test_worker_command_records_runtime_linked_health_snapshot_when_status_snapshot_truth_is_available(): void
    {
        $node = $this->makeNode(1, 'primary-fs');
        $registry = new class($node) implements PbxRegistryInterface
        {
            public function __construct(private readonly PbxNode $node) {}

            public function findById(int $id): PbxNode
            {
                return $this->node;
            }

            public function findBySlug(string $slug): PbxNode
            {
                return $this->node;
            }

            public function allActive(): array
            {
                return [$this->node];
            }

            public function allByCluster(string $cluster): array
            {
                return [$this->node];
            }

            public function allByTags(array $tags): array
            {
                return [$this->node];
            }

            public function allByProvider(string $providerCode): array
            {
                return [$this->node];
            }
        };

        $assignmentResolver = new class($node) implements WorkerAssignmentResolverInterface
        {
            public function __construct(private readonly PbxNode $node) {}

            public function resolveNodes(WorkerAssignment $assignment): array
            {
                return [$this->node];
            }

            public function resolveForWorkerName(string $workerName): array
            {
                return [];
            }
        };

        $connectionResolver = new class implements ConnectionResolverInterface
        {
            public function resolveForNode(int $pbxNodeId): ConnectionContext
            {
                return $this->context('primary-fs');
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
                return new EslCoreConnectionHandle(
                    context: $context,
                    pipeline: (new EslCorePipelineFactory)->createPipeline(),
                    openingSequence: [],
                    closingSequence: [],
                    transportOpener: fn () => new InMemoryTransport,
                );
            }
        };

        $runtimeRunner = new class implements RuntimeRunnerFeedbackProviderInterface, RuntimeRunnerInterface
        {
            private ?RuntimeRunnerFeedback $feedback = null;

            public function run(RuntimeHandoffInterface $handoff): void
            {
                $this->feedback = new RuntimeRunnerFeedback(
                    state: RuntimeRunnerFeedback::STATE_RUNNING,
                    source: 'apntalk/esl-react-runtime-status-snapshot',
                    delivery: 'snapshot',
                    statusPhase: 'active',
                    endpoint: $handoff->endpoint(),
                    sessionId: $handoff->context()->workerSessionId,
                    connectionState: 'authenticated',
                    sessionState: 'active',
                    isConnected: true,
                    isAuthenticated: true,
                    isLive: true,
                    isRuntimeActive: true,
                    isRecoveryInProgress: false,
                    isReconnecting: false,
                    isDraining: false,
                    isStopped: false,
                    reconnectAttempts: 2,
                    lastHeartbeatAtMicros: 1700000.0,
                    lastSuccessfulConnectAtMicros: 1600000.0,
                    lastDisconnectAtMicros: 1650000.0,
                    lastDisconnectReasonClass: \RuntimeException::class,
                    lastDisconnectReasonMessage: 'disconnect observed',
                    lastFailureAtMicros: 1660000.0,
                    lastRuntimeErrorClass: \LogicException::class,
                    lastRuntimeErrorMessage: 'failure summary',
                );
            }

            public function runtimeFeedback(): ?RuntimeRunnerFeedback
            {
                return $this->feedback;
            }
        };

        $healthReporter = new class implements HealthReporterInterface
        {
            /** @var list<HealthSnapshot> */
            public array $recorded = [];

            public function forNode(int $pbxNodeId): HealthSnapshot
            {
                throw new \BadMethodCallException('Not used in worker command health recording test.');
            }

            public function forAllActive(): array
            {
                return [];
            }

            public function forCluster(string $cluster): array
            {
                return [];
            }

            public function record(HealthSnapshot $snapshot): void
            {
                $this->recorded[] = $snapshot;
            }
        };

        $this->app->instance(PbxRegistryInterface::class, $registry);
        $this->app->instance(WorkerAssignmentResolverInterface::class, $assignmentResolver);
        $this->app->instance(ConnectionResolverInterface::class, $connectionResolver);
        $this->app->instance(ConnectionFactoryInterface::class, $connectionFactory);
        $this->app->instance(RuntimeRunnerInterface::class, $runtimeRunner);
        $this->app->instance(HealthReporterInterface::class, $healthReporter);
        $this->app->instance(LoggerInterface::class, new NullLogger);

        $this->artisan('freeswitch:worker', [
            '--worker' => 'health-worker',
            '--pbx' => 'primary-fs',
        ])->assertExitCode(0);

        $this->assertCount(1, $healthReporter->recorded);
        $snapshot = $healthReporter->recorded[0];
        $this->assertSame(HealthSnapshot::STATUS_HEALTHY, $snapshot->status);
        $this->assertSame('authenticated', $snapshot->connectionState);
        $this->assertSame('node', $snapshot->workerAssignmentScope);
        $this->assertSame(2, $snapshot->retryAttempt);
        $this->assertTrue($snapshot->meta['live_runtime_linked']);
        $this->assertSame('apntalk/esl-react-runtime-status-snapshot', $snapshot->meta['runtime_truth_source']);
        $this->assertSame('active', $snapshot->meta['runtime_status_phase']);
        $this->assertTrue($snapshot->meta['runtime_active']);
        $this->assertSame('disconnect observed', $snapshot->meta['runtime_last_disconnect_reason_message']);
        $this->assertSame('failure summary', $snapshot->meta['runtime_last_failure_message']);
    }

    public function test_worker_command_warns_when_non_live_runner_is_selected(): void
    {
        $node = $this->makeNode(1, 'primary-fs');
        $registry = new class($node) implements PbxRegistryInterface
        {
            public int $findBySlugCalls = 0;

            public function __construct(private readonly PbxNode $node) {}

            public function findById(int $id): PbxNode
            {
                return $this->node;
            }

            public function findBySlug(string $slug): PbxNode
            {
                $this->findBySlugCalls++;

                return $this->node;
            }

            public function allActive(): array
            {
                return [$this->node];
            }

            public function allByCluster(string $cluster): array
            {
                return [$this->node];
            }

            public function allByTags(array $tags): array
            {
                return [$this->node];
            }

            public function allByProvider(string $providerCode): array
            {
                return [$this->node];
            }
        };

        $assignmentResolver = new class($node) implements WorkerAssignmentResolverInterface
        {
            public function __construct(private readonly PbxNode $node) {}

            public function resolveNodes(WorkerAssignment $assignment): array
            {
                return [$this->node];
            }

            public function resolveForWorkerName(string $workerName): array
            {
                return [];
            }
        };

        $connectionResolver = new class implements ConnectionResolverInterface
        {
            public function resolveForNode(int $pbxNodeId): ConnectionContext
            {
                return $this->context('primary-fs');
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
        $runtimeRunner = new NonLiveRuntimeRunner;

        $this->app['config']->set('freeswitch-esl.runtime.runner', 'non-live');
        $this->app->instance(PbxRegistryInterface::class, $registry);
        $this->app->instance(WorkerAssignmentResolverInterface::class, $assignmentResolver);
        $this->app->instance(ConnectionResolverInterface::class, $connectionResolver);
        $this->app->instance(ConnectionFactoryInterface::class, $connectionFactory);
        $this->app->instance(RuntimeRunnerInterface::class, $runtimeRunner);
        $this->app->instance(LoggerInterface::class, new NullLogger);

        $this->artisan('freeswitch:worker', [
            '--worker' => 'ingest-worker',
            '--pbx' => 'primary-fs',
        ])
            ->expectsOutputToContain('WARNING: freeswitch-esl.runtime.runner=non-live leaves this worker in a truthful non-live/no-op posture; no live ESL session will be maintained.')
            ->expectsOutputToContain('Prepared runtime handoff for 1/1 node(s); runtime runner invoked for 1/1 node(s); push lifecycle observed for 0/1 node(s); live runtime observed for 0/1 node(s).')
            ->assertExitCode(0);
    }

    public function test_worker_command_db_path_prepares_runtime_handoffs_for_resolved_nodes(): void
    {
        $nodeA = $this->makeNode(1, 'db-node-a');
        $nodeB = $this->makeNode(2, 'db-node-b');

        $registry = new class implements PbxRegistryInterface
        {
            public function findById(int $id): PbxNode
            {
                throw new \BadMethodCallException('Registry should not be used in --db path.');
            }

            public function findBySlug(string $slug): PbxNode
            {
                throw new \BadMethodCallException('Registry should not be used in --db path.');
            }

            public function allActive(): array
            {
                return [];
            }

            public function allByCluster(string $cluster): array
            {
                return [];
            }

            public function allByTags(array $tags): array
            {
                return [];
            }

            public function allByProvider(string $providerCode): array
            {
                return [];
            }
        };

        $assignmentResolver = new class($nodeA, $nodeB) implements WorkerAssignmentResolverInterface
        {
            public int $resolveNodesCalls = 0;

            public int $resolveForWorkerNameCalls = 0;

            public function __construct(
                private readonly PbxNode $nodeA,
                private readonly PbxNode $nodeB,
            ) {}

            public function resolveNodes(WorkerAssignment $assignment): array
            {
                $this->resolveNodesCalls++;

                return [];
            }

            public function resolveForWorkerName(string $workerName): array
            {
                $this->resolveForWorkerNameCalls++;

                return [$this->nodeA, $this->nodeB];
            }
        };

        $connectionResolver = new class implements ConnectionResolverInterface
        {
            /** @var list<string> */
            public array $resolvedNodeSlugs = [];

            public function resolveForNode(int $pbxNodeId): ConnectionContext
            {
                return $this->makeContext('unused');
            }

            public function resolveForSlug(string $slug): ConnectionContext
            {
                return $this->makeContext($slug);
            }

            public function resolveForPbxNode(PbxNode $node): ConnectionContext
            {
                $this->resolvedNodeSlugs[] = $node->slug;

                return $this->makeContext($node->slug, $node->id);
            }

            private function makeContext(string $slug, int $id = 1): ConnectionContext
            {
                return new ConnectionContext(
                    pbxNodeId: $id,
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
            public int $createCalls = 0;

            /** @var list<ConnectionContext> */
            public array $contexts = [];

            public function create(ConnectionContext $context): EslCoreConnectionHandle
            {
                $this->createCalls++;
                $this->contexts[] = $context;

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

        $this->app->instance(PbxRegistryInterface::class, $registry);
        $this->app->instance(WorkerAssignmentResolverInterface::class, $assignmentResolver);
        $this->app->instance(ConnectionResolverInterface::class, $connectionResolver);
        $this->app->instance(ConnectionFactoryInterface::class, $connectionFactory);
        $this->app->instance(RuntimeRunnerInterface::class, $runtimeRunner);
        $this->app->instance(LoggerInterface::class, new NullLogger);

        $this->artisan('freeswitch:worker', [
            '--worker' => 'db-worker',
            '--db' => true,
        ])
            ->expectsOutputToContain('Starting worker [db-worker] from DB assignment (worker_assignments table) — 2 node(s).')
            ->expectsOutputToContain('Prepared runtime handoff for 2/2 node(s); runtime runner invoked for 2/2 node(s); push lifecycle observed for 0/2 node(s); live runtime observed for 0/2 node(s).')
            ->assertExitCode(0);

        $this->assertSame(0, $assignmentResolver->resolveNodesCalls);
        $this->assertSame(1, $assignmentResolver->resolveForWorkerNameCalls);
        $this->assertSame(2, $connectionFactory->createCalls);
        $this->assertCount(2, $connectionFactory->contexts);
        $this->assertSame('db-node-a', $connectionFactory->contexts[0]->pbxNodeSlug);
        $this->assertSame('db-node-b', $connectionFactory->contexts[1]->pbxNodeSlug);
        $this->assertNotNull($connectionFactory->contexts[0]->workerSessionId);
        $this->assertNotNull($connectionFactory->contexts[1]->workerSessionId);
        $this->assertSame(2, $runtimeRunner->runCalls);
    }

    public function test_worker_command_surfaces_bounded_replay_recovery_posture_when_checkpoint_exists(): void
    {
        $node = $this->makeNode(1, 'primary-fs');
        $registry = new class($node) implements PbxRegistryInterface
        {
            public function __construct(private readonly PbxNode $node) {}

            public function findById(int $id): PbxNode
            {
                return $this->node;
            }

            public function findBySlug(string $slug): PbxNode
            {
                return $this->node;
            }

            public function allActive(): array
            {
                return [$this->node];
            }

            public function allByCluster(string $cluster): array
            {
                return [$this->node];
            }

            public function allByTags(array $tags): array
            {
                return [$this->node];
            }

            public function allByProvider(string $providerCode): array
            {
                return [$this->node];
            }
        };

        $assignmentResolver = new class($node) implements WorkerAssignmentResolverInterface
        {
            public function __construct(private readonly PbxNode $node) {}

            public function resolveNodes(WorkerAssignment $assignment): array
            {
                return [$this->node];
            }

            public function resolveForWorkerName(string $workerName): array
            {
                return [];
            }
        };

        $connectionResolver = new class implements ConnectionResolverInterface
        {
            public function resolveForNode(int $pbxNodeId): ConnectionContext
            {
                return $this->makeContext();
            }

            public function resolveForSlug(string $slug): ConnectionContext
            {
                return $this->makeContext();
            }

            public function resolveForPbxNode(PbxNode $node): ConnectionContext
            {
                return $this->makeContext($node->slug);
            }

            private function makeContext(string $slug = 'primary-fs'): ConnectionContext
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

        $checkpointStore = new class implements ReplayCheckpointStoreInterface
        {
            public function save(ReplayCheckpoint $checkpoint): void {}

            public function load(string $key): ?ReplayCheckpoint
            {
                return new ReplayCheckpoint(
                    key: $key,
                    cursor: new ReplayReadCursor(1, 10),
                    savedAt: new \DateTimeImmutable('2026-04-17T12:00:00+00:00'),
                    metadata: [
                        'checkpoint_reason' => 'drain-completed',
                        'replay_session_id' => 'replay-session-a',
                        'worker_session_id' => 'worker-session-prev',
                        'job_uuid' => 'job-123',
                        'pbx_node_slug' => 'primary-fs',
                    ],
                );
            }

            public function exists(string $key): bool
            {
                return true;
            }

            public function delete(string $key): void {}

            public function find(ReplayCheckpointCriteria $criteria): array
            {
                return [$this->load('worker-runtime.ingest-worker.freeswitch.primary-fs.default')];
            }
        };

        $artifactStore = new class implements ReplayArtifactStoreInterface
        {
            public function write(CapturedArtifactEnvelope $artifact): ReplayRecordId
            {
                throw new \BadMethodCallException('write() should not be called in this command test.');
            }

            public function readById(ReplayRecordId $id): ?StoredReplayRecord
            {
                return null;
            }

            public function readFromCursor(ReplayReadCursor $cursor, int $limit = 100, ?ReplayReadCriteria $criteria = null): array
            {
                if ($cursor->lastConsumedSequence >= 1) {
                    return [
                        new StoredReplayRecord(
                            id: new ReplayRecordId('00000000-0000-4000-8000-000000000001'),
                            artifactVersion: '1',
                            artifactName: 'event.capture',
                            captureTimestamp: new \DateTimeImmutable('2026-04-17T12:01:00+00:00'),
                            storedAt: new \DateTimeImmutable('2026-04-17T12:01:00+00:00'),
                            appendSequence: 2,
                            connectionGeneration: null,
                            sessionId: 'replay-session-a',
                            jobUuid: 'job-123',
                            eventName: 'HEARTBEAT',
                            capturePath: null,
                            correlationIds: ['replay_session_id' => 'replay-session-a'],
                            runtimeFlags: [
                                'provider_code' => 'freeswitch',
                                'pbx_node_slug' => 'primary-fs',
                                'worker_session_id' => 'worker-session-prev',
                                'connection_profile_name' => 'default',
                            ],
                            payload: [],
                            checksum: 'checksum',
                            tags: [],
                        ),
                    ];
                }

                return [];
            }

            public function openCursor(): ReplayReadCursor
            {
                return ReplayReadCursor::start();
            }
        };

        $this->app->instance(PbxRegistryInterface::class, $registry);
        $this->app->instance(WorkerAssignmentResolverInterface::class, $assignmentResolver);
        $this->app->instance(ConnectionResolverInterface::class, $connectionResolver);
        $this->app->instance(ConnectionFactoryInterface::class, $connectionFactory);
        $this->app->instance(RuntimeRunnerInterface::class, $runtimeRunner);
        $this->app->instance(LoggerInterface::class, new NullLogger);
        $this->app->instance(
            WorkerReplayCheckpointManager::class,
            new WorkerReplayCheckpointManager(
                artifactStore: $artifactStore,
                checkpointRepository: new ReplayCheckpointRepository($checkpointStore),
                logger: new NullLogger,
                enabled: true,
            ),
        );

        $this->artisan('freeswitch:worker', [
            '--worker' => 'ingest-worker',
            '--pbx' => 'primary-fs',
        ])
            ->expectsOutputToContain('- primary-fs: checkpoint scope=worker-runtime.ingest-worker.freeswitch.primary-fs.default, reason=drain-completed, saved_at=2026-04-17T12:00:00.000+00:00; prior checkpoint yes; recovery hint candidate-after-sequence-2; anchors replay=replay-session-a, worker=worker-session-prev, job=job-123, pbx=primary-fs; drain idle')
            ->assertExitCode(0);
    }

    public function test_worker_command_can_emit_machine_readable_json_recovery_surface(): void
    {
        $node = $this->makeNode(1, 'primary-fs');
        $registry = new class($node) implements PbxRegistryInterface
        {
            public function __construct(private readonly PbxNode $node) {}

            public function findById(int $id): PbxNode
            {
                return $this->node;
            }

            public function findBySlug(string $slug): PbxNode
            {
                return $this->node;
            }

            public function allActive(): array
            {
                return [$this->node];
            }

            public function allByCluster(string $cluster): array
            {
                return [$this->node];
            }

            public function allByTags(array $tags): array
            {
                return [$this->node];
            }

            public function allByProvider(string $providerCode): array
            {
                return [$this->node];
            }
        };

        $assignmentResolver = new class($node) implements WorkerAssignmentResolverInterface
        {
            public function __construct(private readonly PbxNode $node) {}

            public function resolveNodes(WorkerAssignment $assignment): array
            {
                return [$this->node];
            }

            public function resolveForWorkerName(string $workerName): array
            {
                return [];
            }
        };

        $connectionResolver = new class implements ConnectionResolverInterface
        {
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
                return $this->context($node->slug);
            }

            private function context(string $slug = 'primary-fs'): ConnectionContext
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

        $checkpointStore = new class implements ReplayCheckpointStoreInterface
        {
            public function save(ReplayCheckpoint $checkpoint): void {}

            public function load(string $key): ?ReplayCheckpoint
            {
                return new ReplayCheckpoint(
                    key: $key,
                    cursor: new ReplayReadCursor(4, 40),
                    savedAt: new \DateTimeImmutable('2026-04-17T12:00:00+00:00'),
                    metadata: [
                        'checkpoint_reason' => 'drain-timeout',
                        'replay_session_id' => 'replay-session-json',
                        'worker_session_id' => 'worker-session-json-prev',
                        'job_uuid' => 'job-json-123',
                        'pbx_node_slug' => 'primary-fs',
                    ],
                );
            }

            public function exists(string $key): bool
            {
                return true;
            }

            public function delete(string $key): void {}

            public function find(ReplayCheckpointCriteria $criteria): array
            {
                return [$this->load('worker-runtime.json-worker.freeswitch.primary-fs.default')];
            }
        };

        $artifactStore = new class implements ReplayArtifactStoreInterface
        {
            public function write(CapturedArtifactEnvelope $artifact): ReplayRecordId
            {
                throw new \BadMethodCallException('write() should not be called in this command test.');
            }

            public function readById(ReplayRecordId $id): ?StoredReplayRecord
            {
                return null;
            }

            public function readFromCursor(ReplayReadCursor $cursor, int $limit = 100, ?ReplayReadCriteria $criteria = null): array
            {
                return [
                    new StoredReplayRecord(
                        id: new ReplayRecordId('00000000-0000-4000-8000-000000000002'),
                        artifactVersion: '1',
                        artifactName: 'event.capture',
                        captureTimestamp: new \DateTimeImmutable('2026-04-17T12:01:00+00:00'),
                        storedAt: new \DateTimeImmutable('2026-04-17T12:01:00+00:00'),
                        appendSequence: 5,
                        connectionGeneration: null,
                        sessionId: 'replay-session-json',
                        jobUuid: 'job-json-123',
                        eventName: 'HEARTBEAT',
                        capturePath: null,
                        correlationIds: ['replay_session_id' => 'replay-session-json'],
                        runtimeFlags: [
                            'provider_code' => 'freeswitch',
                            'pbx_node_slug' => 'primary-fs',
                            'worker_session_id' => 'worker-session-json-prev',
                            'connection_profile_name' => 'default',
                        ],
                        payload: [],
                        checksum: 'checksum-json',
                        tags: [],
                    ),
                ];
            }

            public function openCursor(): ReplayReadCursor
            {
                return ReplayReadCursor::start();
            }
        };

        $this->app->instance(PbxRegistryInterface::class, $registry);
        $this->app->instance(WorkerAssignmentResolverInterface::class, $assignmentResolver);
        $this->app->instance(ConnectionResolverInterface::class, $connectionResolver);
        $this->app->instance(ConnectionFactoryInterface::class, $connectionFactory);
        $this->app->instance(RuntimeRunnerInterface::class, $runtimeRunner);
        $this->app->instance(LoggerInterface::class, new NullLogger);
        $this->app->instance(
            WorkerReplayCheckpointManager::class,
            new WorkerReplayCheckpointManager(
                artifactStore: $artifactStore,
                checkpointRepository: new ReplayCheckpointRepository($checkpointStore),
                logger: new NullLogger,
                enabled: true,
            ),
        );

        /** @var Kernel $kernel */
        $kernel = $this->app->make(Kernel::class);

        $exitCode = $kernel->call('freeswitch:worker', [
            '--worker' => 'json-worker',
            '--pbx' => 'primary-fs',
            '--json' => true,
        ]);

        $output = trim($kernel->output());

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString(
            'Replay-backed checkpoint/recovery posture reflects persisted replay artifacts only',
            $output,
        );
        $this->assertStringNotContainsString('Starting worker [json-worker]', $output);

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($output, true);

        $this->assertIsArray($decoded);
        $this->assertSame('json-worker', $decoded['worker_name']);
        $this->assertSame('replay_checkpoint_posture', $decoded['recovery_surface']);
        $this->assertFalse($decoded['live_recovery_supported']);
        $this->assertSame([
            'node_count' => 1,
            'prepared_count' => 1,
            'runtime_runner_invoked_count' => 1,
            'push_lifecycle_observed_count' => 0,
            'live_runtime_observed_count' => 0,
        ], $decoded['summary']);
        $this->assertCount(1, $decoded['nodes']);
        $this->assertSame('primary-fs', $decoded['nodes'][0]['pbx_node_slug']);
        $this->assertSame('running', $decoded['nodes'][0]['worker_runtime_state']);
        $this->assertNull($decoded['nodes'][0]['runtime_status_phase']);
        $this->assertNull($decoded['nodes'][0]['runtime_active']);
        $this->assertNull($decoded['nodes'][0]['runtime_recovery_in_progress']);
        $this->assertTrue($decoded['nodes'][0]['checkpoint_enabled']);
        $this->assertSame(
            'worker-runtime.json-worker.freeswitch.primary-fs.default',
            $decoded['nodes'][0]['checkpoint_key'],
        );
        $this->assertSame('2026-04-17T12:00:00.000+00:00', $decoded['nodes'][0]['checkpoint_saved_at']);
        $this->assertSame('drain-timeout', $decoded['nodes'][0]['checkpoint_reason']);
        $this->assertTrue($decoded['nodes'][0]['checkpoint_prior_observed']);
        $this->assertTrue($decoded['nodes'][0]['checkpoint_recovery_supported']);
        $this->assertTrue($decoded['nodes'][0]['checkpoint_recovery_candidate_found']);
        $this->assertSame(5, $decoded['nodes'][0]['checkpoint_recovery_next_sequence']);
        $this->assertSame('replay-session-json', $decoded['nodes'][0]['checkpoint_recovery_replay_session_id']);
        $this->assertSame('worker-session-json-prev', $decoded['nodes'][0]['checkpoint_recovery_worker_session_id']);
        $this->assertSame('job-json-123', $decoded['nodes'][0]['checkpoint_recovery_job_uuid']);
        $this->assertSame('primary-fs', $decoded['nodes'][0]['checkpoint_recovery_pbx_node_slug']);
        $this->assertTrue($decoded['nodes'][0]['resume_supported']);
        $this->assertFalse($decoded['nodes'][0]['resume_execution_supported']);
        $this->assertSame('checkpoint_recovery_metadata', $decoded['nodes'][0]['resume_posture_basis']);
        $this->assertTrue($decoded['nodes'][0]['resume_checkpoint_available']);
        $this->assertTrue($decoded['nodes'][0]['resume_candidate_available']);
        $this->assertSame(5, $decoded['nodes'][0]['resume_candidate_sequence']);
        $this->assertSame('replay-session-json', $decoded['nodes'][0]['resume_candidate_replay_session_id']);
        $this->assertSame('worker-session-json-prev', $decoded['nodes'][0]['resume_candidate_worker_session_id']);
        $this->assertSame('job-json-123', $decoded['nodes'][0]['resume_candidate_job_uuid']);
        $this->assertSame('primary-fs', $decoded['nodes'][0]['resume_candidate_pbx_node_slug']);
        $this->assertSame('worker_replay_checkpoint_manager', $decoded['nodes'][0]['resume_posture_source']);
        $this->assertTrue($decoded['nodes'][0]['resume_execution_deferred']);
        $this->assertFalse($decoded['nodes'][0]['drain_requested']);
        $this->assertFalse($decoded['nodes'][0]['drain_completed']);
        $this->assertFalse($decoded['nodes'][0]['drain_timed_out']);
        $this->assertNull($decoded['nodes'][0]['drain_started_at']);
        $this->assertNull($decoded['nodes'][0]['drain_deadline_at']);
    }

    public function test_worker_command_machine_readable_node_status_uses_stable_recovery_fields(): void
    {
        $status = new WorkerStatus(
            sessionId: 'runtime-session-1',
            workerName: 'json-worker',
            state: WorkerStatus::STATE_RUNNING,
            assignedNodeSlugs: ['primary-fs'],
            inflightCount: 0,
            retryAttempt: 0,
            isDraining: false,
            lastHeartbeatAt: null,
            bootedAt: null,
            meta: [
                'runtime_status_phase' => 'reconnecting',
                'runtime_active' => true,
                'runtime_recovery_in_progress' => true,
                'runtime_connection_state' => 'reconnecting',
                'runtime_session_state' => 'disconnected',
                'runtime_authenticated' => false,
                'runtime_reconnect_attempts' => 5,
                'runtime_last_heartbeat_at' => '2026-04-17T12:04:00.000+00:00',
                'runtime_last_successful_connect_at' => '2026-04-17T12:00:00.000+00:00',
                'runtime_last_disconnect_at' => '2026-04-17T12:04:30.000+00:00',
                'runtime_last_disconnect_reason_class' => \RuntimeException::class,
                'runtime_last_disconnect_reason_message' => 'disconnect observed',
                'runtime_last_failure_at' => '2026-04-17T12:04:45.000+00:00',
                'runtime_last_error_class' => \LogicException::class,
                'runtime_last_error_message' => 'failure summary',
                'checkpoint_enabled' => true,
                'checkpoint_key' => 'worker-runtime.json-worker.freeswitch.primary-fs.default',
                'checkpoint_saved_at' => '2026-04-17T12:00:00.000+00:00',
                'checkpoint_reason' => 'drain-timeout',
                'checkpoint_is_resuming' => true,
                'checkpoint_recovery_supported' => true,
                'checkpoint_recovery_candidate_found' => true,
                'checkpoint_recovery_next_sequence' => 5,
                'checkpoint_recovery_replay_session_id' => 'replay-session-json',
                'checkpoint_recovery_worker_session_id' => 'worker-session-json-prev',
                'checkpoint_recovery_job_uuid' => 'job-json-123',
                'checkpoint_recovery_pbx_node_slug' => 'primary-fs',
                'drain_started_at' => '2026-04-17T12:05:00.000+00:00',
                'drain_deadline_at' => '2026-04-17T12:05:30.000+00:00',
                'drain_completed' => false,
                'drain_timed_out' => false,
            ],
        );

        $result = (new WorkerStatusReportBuilder)->machineReadableNodeStatus('primary-fs', $status);

        $this->assertSame('primary-fs', $result['pbx_node_slug']);
        $this->assertSame(WorkerStatus::STATE_RUNNING, $result['worker_runtime_state']);
        $this->assertSame('reconnecting', $result['runtime_status_phase']);
        $this->assertTrue($result['runtime_active']);
        $this->assertTrue($result['runtime_recovery_in_progress']);
        $this->assertSame('reconnecting', $result['runtime_connection_state']);
        $this->assertSame('disconnected', $result['runtime_session_state']);
        $this->assertFalse($result['runtime_authenticated']);
        $this->assertSame(5, $result['runtime_reconnect_attempts']);
        $this->assertSame('2026-04-17T12:04:00.000+00:00', $result['runtime_last_heartbeat_at']);
        $this->assertSame('2026-04-17T12:00:00.000+00:00', $result['runtime_last_successful_connect_at']);
        $this->assertSame('2026-04-17T12:04:30.000+00:00', $result['runtime_last_disconnect_at']);
        $this->assertSame(\RuntimeException::class, $result['runtime_last_disconnect_reason_class']);
        $this->assertSame('disconnect observed', $result['runtime_last_disconnect_reason_message']);
        $this->assertSame('2026-04-17T12:04:45.000+00:00', $result['runtime_last_failure_at']);
        $this->assertSame(\LogicException::class, $result['runtime_last_failure_class']);
        $this->assertSame('failure summary', $result['runtime_last_failure_message']);
        $this->assertTrue($result['checkpoint_enabled']);
        $this->assertSame('worker-runtime.json-worker.freeswitch.primary-fs.default', $result['checkpoint_key']);
        $this->assertSame('2026-04-17T12:00:00.000+00:00', $result['checkpoint_saved_at']);
        $this->assertSame('drain-timeout', $result['checkpoint_reason']);
        $this->assertTrue($result['checkpoint_prior_observed']);
        $this->assertTrue($result['checkpoint_recovery_supported']);
        $this->assertTrue($result['checkpoint_recovery_candidate_found']);
        $this->assertSame(5, $result['checkpoint_recovery_next_sequence']);
        $this->assertSame('replay-session-json', $result['checkpoint_recovery_replay_session_id']);
        $this->assertSame('worker-session-json-prev', $result['checkpoint_recovery_worker_session_id']);
        $this->assertSame('job-json-123', $result['checkpoint_recovery_job_uuid']);
        $this->assertSame('primary-fs', $result['checkpoint_recovery_pbx_node_slug']);
        $this->assertTrue($result['resume_supported']);
        $this->assertFalse($result['resume_execution_supported']);
        $this->assertSame('checkpoint_recovery_metadata', $result['resume_posture_basis']);
        $this->assertTrue($result['resume_checkpoint_available']);
        $this->assertTrue($result['resume_candidate_available']);
        $this->assertSame(5, $result['resume_candidate_sequence']);
        $this->assertSame('replay-session-json', $result['resume_candidate_replay_session_id']);
        $this->assertSame('worker-session-json-prev', $result['resume_candidate_worker_session_id']);
        $this->assertSame('job-json-123', $result['resume_candidate_job_uuid']);
        $this->assertSame('primary-fs', $result['resume_candidate_pbx_node_slug']);
        $this->assertSame('worker_replay_checkpoint_manager', $result['resume_posture_source']);
        $this->assertTrue($result['resume_execution_deferred']);
        $this->assertTrue($result['drain_requested']);
        $this->assertFalse($result['drain_completed']);
        $this->assertFalse($result['drain_timed_out']);
        $this->assertSame('2026-04-17T12:05:00.000+00:00', $result['drain_started_at']);
        $this->assertSame('2026-04-17T12:05:30.000+00:00', $result['drain_deadline_at']);
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
