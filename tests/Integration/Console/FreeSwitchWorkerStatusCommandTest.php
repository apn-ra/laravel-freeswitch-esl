<?php

namespace ApnTalk\LaravelFreeswitchEsl\Tests\Integration\Console;

use Apntalk\EslReplay\Checkpoint\ReplayCheckpoint;
use Apntalk\EslReplay\Checkpoint\ReplayCheckpointRepository;
use Apntalk\EslReplay\Contracts\ReplayArtifactStoreInterface;
use Apntalk\EslReplay\Contracts\ReplayCheckpointStoreInterface;
use Apntalk\EslReplay\Cursor\ReplayReadCursor;
use Apntalk\EslReplay\Read\ReplayReadCriteria;
use Apntalk\EslReplay\Storage\ReplayRecordId;
use Apntalk\EslReplay\Storage\StoredReplayRecord;
use ApnTalk\LaravelFreeswitchEsl\Contracts\ConnectionFactoryInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\ConnectionResolverInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\PbxRegistryInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\RuntimeHandoffInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\RuntimeRunnerInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\WorkerAssignmentResolverInterface;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\ConnectionContext;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\PbxNode;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\WorkerAssignment;
use ApnTalk\LaravelFreeswitchEsl\Exceptions\PbxNotFoundException;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslCoreCommandFactory;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslCoreConnectionHandle;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslCorePipelineFactory;
use ApnTalk\LaravelFreeswitchEsl\Integration\Replay\WorkerReplayCheckpointManager;
use ApnTalk\LaravelFreeswitchEsl\Tests\TestCase;
use Apntalk\EslCore\Transport\InMemoryTransport;
use Illuminate\Contracts\Console\Kernel;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class FreeSwitchWorkerStatusCommandTest extends TestCase
{
    public function test_worker_status_command_is_registered_with_the_console_kernel(): void
    {
        /** @var Kernel $kernel */
        $kernel = $this->app->make(Kernel::class);

        $this->assertArrayHasKey('freeswitch:worker:status', $kernel->all());
    }

    public function test_worker_status_command_reports_machine_readable_ephemeral_status_without_invoking_runner(): void
    {
        $node = $this->makeNode(1, 'primary-fs');
        $registry = $this->registryForNodes([$node]);
        $assignmentResolver = new class ($node) implements WorkerAssignmentResolverInterface {
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

        $runtimeRunner = new class implements RuntimeRunnerInterface {
            public int $runCalls = 0;

            public function run(RuntimeHandoffInterface $handoff): void
            {
                $this->runCalls++;
            }
        };

        $this->bindRuntimeDependencies(
            registry: $registry,
            assignmentResolver: $assignmentResolver,
            runtimeRunner: $runtimeRunner,
            checkpointManager: $this->checkpointManager(
                checkpointReason: 'drain-completed',
                replaySessionId: 'replay-session-status',
                workerSessionId: 'worker-session-status-prev',
                jobUuid: 'job-status-123',
                nextSequence: 3,
            ),
        );

        /** @var Kernel $kernel */
        $kernel = $this->app->make(Kernel::class);

        $exitCode = $kernel->call('freeswitch:worker:status', [
            '--worker' => ['status-worker'],
            '--pbx' => 'primary-fs',
        ]);

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode(trim($kernel->output()), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, $runtimeRunner->runCalls);
        $this->assertIsArray($decoded);
        $this->assertSame('worker_runtime_status', $decoded['report_surface']);
        $this->assertFalse($decoded['live_recovery_supported']);
        $this->assertCount(1, $decoded['workers']);
        $this->assertSame('status-worker', $decoded['workers'][0]['worker_name']);
        $this->assertSame('node', $decoded['workers'][0]['assignment_mode']);
        $this->assertSame([
            'node_count' => 1,
            'prepared_count' => 1,
            'runtime_runner_invoked_count' => 0,
            'push_lifecycle_observed_count' => 0,
            'live_runtime_observed_count' => 0,
        ], $decoded['workers'][0]['summary']);
        $this->assertSame('primary-fs', $decoded['workers'][0]['nodes'][0]['pbx_node_slug']);
        $this->assertSame('running', $decoded['workers'][0]['nodes'][0]['worker_runtime_state']);
        $this->assertTrue($decoded['workers'][0]['nodes'][0]['checkpoint_enabled']);
        $this->assertSame(
            'worker-runtime.status-worker.freeswitch.primary-fs.default',
            $decoded['workers'][0]['nodes'][0]['checkpoint_key'],
        );
        $this->assertSame('drain-completed', $decoded['workers'][0]['nodes'][0]['checkpoint_reason']);
        $this->assertTrue($decoded['workers'][0]['nodes'][0]['checkpoint_prior_observed']);
        $this->assertTrue($decoded['workers'][0]['nodes'][0]['checkpoint_recovery_supported']);
        $this->assertTrue($decoded['workers'][0]['nodes'][0]['checkpoint_recovery_candidate_found']);
        $this->assertSame(3, $decoded['workers'][0]['nodes'][0]['checkpoint_recovery_next_sequence']);
        $this->assertSame('replay-session-status', $decoded['workers'][0]['nodes'][0]['checkpoint_recovery_replay_session_id']);
        $this->assertSame('worker-session-status-prev', $decoded['workers'][0]['nodes'][0]['checkpoint_recovery_worker_session_id']);
        $this->assertSame('job-status-123', $decoded['workers'][0]['nodes'][0]['checkpoint_recovery_job_uuid']);
        $this->assertSame('primary-fs', $decoded['workers'][0]['nodes'][0]['checkpoint_recovery_pbx_node_slug']);
        $this->assertTrue($decoded['workers'][0]['nodes'][0]['resume_supported']);
        $this->assertFalse($decoded['workers'][0]['nodes'][0]['resume_execution_supported']);
        $this->assertSame('checkpoint_recovery_metadata', $decoded['workers'][0]['nodes'][0]['resume_posture_basis']);
        $this->assertTrue($decoded['workers'][0]['nodes'][0]['resume_checkpoint_available']);
        $this->assertTrue($decoded['workers'][0]['nodes'][0]['resume_candidate_available']);
        $this->assertSame(3, $decoded['workers'][0]['nodes'][0]['resume_candidate_sequence']);
        $this->assertSame('replay-session-status', $decoded['workers'][0]['nodes'][0]['resume_candidate_replay_session_id']);
        $this->assertSame('worker-session-status-prev', $decoded['workers'][0]['nodes'][0]['resume_candidate_worker_session_id']);
        $this->assertSame('job-status-123', $decoded['workers'][0]['nodes'][0]['resume_candidate_job_uuid']);
        $this->assertSame('primary-fs', $decoded['workers'][0]['nodes'][0]['resume_candidate_pbx_node_slug']);
        $this->assertSame('worker_replay_checkpoint_manager', $decoded['workers'][0]['nodes'][0]['resume_posture_source']);
        $this->assertTrue($decoded['workers'][0]['nodes'][0]['resume_execution_deferred']);
        $this->assertFalse($decoded['workers'][0]['nodes'][0]['drain_requested']);
        $this->assertFalse($decoded['workers'][0]['nodes'][0]['drain_completed']);
        $this->assertFalse($decoded['workers'][0]['nodes'][0]['drain_timed_out']);
    }

    public function test_worker_status_command_can_report_multiple_db_backed_workers(): void
    {
        $primary = $this->makeNode(1, 'primary-fs');
        $edge = $this->makeNode(2, 'edge-fs');
        $registry = $this->registryForNodes([$primary, $edge]);
        $assignmentResolver = new class ($primary, $edge) implements WorkerAssignmentResolverInterface {
            public function __construct(
                private readonly PbxNode $primary,
                private readonly PbxNode $edge,
            ) {}

            public function resolveNodes(WorkerAssignment $assignment): array
            {
                return [];
            }

            public function resolveForWorkerName(string $workerName): array
            {
                return match ($workerName) {
                    'worker-a' => [$this->primary],
                    'worker-b' => [$this->edge],
                    default => [],
                };
            }
        };

        $runtimeRunner = new class implements RuntimeRunnerInterface {
            public int $runCalls = 0;

            public function run(RuntimeHandoffInterface $handoff): void
            {
                $this->runCalls++;
            }
        };

        $this->bindRuntimeDependencies(
            registry: $registry,
            assignmentResolver: $assignmentResolver,
            runtimeRunner: $runtimeRunner,
            checkpointManager: $this->disabledCheckpointManager(),
        );

        /** @var Kernel $kernel */
        $kernel = $this->app->make(Kernel::class);

        $exitCode = $kernel->call('freeswitch:worker:status', [
            '--db' => true,
            '--worker' => ['worker-a', 'worker-b', 'worker-empty'],
        ]);

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode(trim($kernel->output()), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, $runtimeRunner->runCalls);
        $this->assertIsArray($decoded);
        $this->assertCount(3, $decoded['workers']);
        $this->assertSame('worker-a', $decoded['workers'][0]['worker_name']);
        $this->assertSame('db-backed', $decoded['workers'][0]['assignment_mode']);
        $this->assertSame(1, $decoded['workers'][0]['summary']['node_count']);
        $this->assertSame('primary-fs', $decoded['workers'][0]['nodes'][0]['pbx_node_slug']);
        $this->assertFalse($decoded['workers'][0]['nodes'][0]['resume_supported']);
        $this->assertFalse($decoded['workers'][0]['nodes'][0]['resume_execution_supported']);
        $this->assertSame('checkpointing_disabled', $decoded['workers'][0]['nodes'][0]['resume_posture_basis']);
        $this->assertFalse($decoded['workers'][0]['nodes'][0]['resume_checkpoint_available']);
        $this->assertFalse($decoded['workers'][0]['nodes'][0]['resume_candidate_available']);
        $this->assertNull($decoded['workers'][0]['nodes'][0]['resume_candidate_sequence']);
        $this->assertNull($decoded['workers'][0]['nodes'][0]['resume_candidate_replay_session_id']);
        $this->assertNull($decoded['workers'][0]['nodes'][0]['resume_candidate_worker_session_id']);
        $this->assertNull($decoded['workers'][0]['nodes'][0]['resume_candidate_job_uuid']);
        $this->assertNull($decoded['workers'][0]['nodes'][0]['resume_candidate_pbx_node_slug']);
        $this->assertSame('worker_replay_checkpoint_manager', $decoded['workers'][0]['nodes'][0]['resume_posture_source']);
        $this->assertTrue($decoded['workers'][0]['nodes'][0]['resume_execution_deferred']);
        $this->assertSame('worker-b', $decoded['workers'][1]['worker_name']);
        $this->assertSame('edge-fs', $decoded['workers'][1]['nodes'][0]['pbx_node_slug']);
        $this->assertSame('worker-empty', $decoded['workers'][2]['worker_name']);
        $this->assertSame(0, $decoded['workers'][2]['summary']['node_count']);
        $this->assertSame([], $decoded['workers'][2]['nodes']);
    }

    /**
     * @param  list<PbxNode>  $nodes
     */
    private function registryForNodes(array $nodes): PbxRegistryInterface
    {
        return new class ($nodes) implements PbxRegistryInterface {
            /**
             * @param  list<PbxNode>  $nodes
             */
            public function __construct(private readonly array $nodes) {}

            public function findById(int $id): PbxNode
            {
                foreach ($this->nodes as $node) {
                    if ($node->id === $id) {
                        return $node;
                    }
                }

                throw new \RuntimeException(sprintf('Unknown node id [%d].', $id));
            }

            public function findBySlug(string $slug): PbxNode
            {
                foreach ($this->nodes as $node) {
                    if ($node->slug === $slug) {
                        return $node;
                    }
                }

                throw new PbxNotFoundException($slug);
            }

            public function allActive(): array
            {
                return $this->nodes;
            }

            public function allByCluster(string $cluster): array
            {
                return $this->nodes;
            }

            public function allByTags(array $tags): array
            {
                return $this->nodes;
            }

            public function allByProvider(string $providerCode): array
            {
                return $this->nodes;
            }
        };
    }

    private function bindRuntimeDependencies(
        PbxRegistryInterface $registry,
        WorkerAssignmentResolverInterface $assignmentResolver,
        RuntimeRunnerInterface $runtimeRunner,
        WorkerReplayCheckpointManager $checkpointManager,
    ): void {
        $connectionResolver = new class implements ConnectionResolverInterface {
            public function resolveForNode(int $pbxNodeId): ConnectionContext
            {
                return $this->context();
            }

            public function resolveForSlug(string $slug): ConnectionContext
            {
                return $this->context($slug);
            }

            public function resolveForPbxNode(PbxNode $node): ConnectionContext
            {
                return $this->context($node->slug, $node->id);
            }

            private function context(string $slug = 'primary-fs', int $id = 1): ConnectionContext
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

        $this->app->instance(PbxRegistryInterface::class, $registry);
        $this->app->instance(WorkerAssignmentResolverInterface::class, $assignmentResolver);
        $this->app->instance(ConnectionResolverInterface::class, $connectionResolver);
        $this->app->instance(ConnectionFactoryInterface::class, $connectionFactory);
        $this->app->instance(RuntimeRunnerInterface::class, $runtimeRunner);
        $this->app->instance(LoggerInterface::class, new NullLogger());
        $this->app->instance(WorkerReplayCheckpointManager::class, $checkpointManager);
    }

    private function checkpointManager(
        string $checkpointReason,
        string $replaySessionId,
        string $workerSessionId,
        string $jobUuid,
        int $nextSequence,
    ): WorkerReplayCheckpointManager {
        $checkpointStore = new class (
            $checkpointReason,
            $replaySessionId,
            $workerSessionId,
            $jobUuid,
        ) implements ReplayCheckpointStoreInterface {
            public function __construct(
                private readonly string $checkpointReason,
                private readonly string $replaySessionId,
                private readonly string $workerSessionId,
                private readonly string $jobUuid,
            ) {}

            public function save(ReplayCheckpoint $checkpoint): void
            {
            }

            public function load(string $key): ?ReplayCheckpoint
            {
                return new ReplayCheckpoint(
                    key: $key,
                    cursor: new ReplayReadCursor(2, 20),
                    savedAt: new \DateTimeImmutable('2026-04-17T12:00:00+00:00'),
                    metadata: [
                        'checkpoint_reason' => $this->checkpointReason,
                        'replay_session_id' => $this->replaySessionId,
                        'worker_session_id' => $this->workerSessionId,
                        'job_uuid' => $this->jobUuid,
                        'pbx_node_slug' => 'primary-fs',
                    ],
                );
            }

            public function exists(string $key): bool
            {
                return true;
            }

            public function delete(string $key): void
            {
            }

            public function find(\Apntalk\EslReplay\Checkpoint\ReplayCheckpointCriteria $criteria): array
            {
                return [$this->load('worker-runtime.status-worker.freeswitch.primary-fs.default')];
            }
        };

        $artifactStore = new class ($replaySessionId, $workerSessionId, $jobUuid, $nextSequence) implements ReplayArtifactStoreInterface {
            public function __construct(
                private readonly string $replaySessionId,
                private readonly string $workerSessionId,
                private readonly string $jobUuid,
                private readonly int $nextSequence,
            ) {}

            public function write(\Apntalk\EslReplay\Artifact\CapturedArtifactEnvelope $artifact): ReplayRecordId
            {
                throw new \BadMethodCallException('write() should not be called in this status command test.');
            }

            public function readById(ReplayRecordId $id): ?StoredReplayRecord
            {
                return null;
            }

            public function readFromCursor(ReplayReadCursor $cursor, int $limit = 100, ?ReplayReadCriteria $criteria = null): array
            {
                return [
                    new StoredReplayRecord(
                        id: new ReplayRecordId('00000000-0000-4000-8000-000000000099'),
                        artifactVersion: '1',
                        artifactName: 'event.capture',
                        captureTimestamp: new \DateTimeImmutable('2026-04-17T12:01:00+00:00'),
                        storedAt: new \DateTimeImmutable('2026-04-17T12:01:00+00:00'),
                        appendSequence: $this->nextSequence,
                        connectionGeneration: null,
                        sessionId: $this->replaySessionId,
                        jobUuid: $this->jobUuid,
                        eventName: 'HEARTBEAT',
                        capturePath: null,
                        correlationIds: ['replay_session_id' => $this->replaySessionId],
                        runtimeFlags: [
                            'provider_code' => 'freeswitch',
                            'pbx_node_slug' => 'primary-fs',
                            'worker_session_id' => $this->workerSessionId,
                            'connection_profile_name' => 'default',
                        ],
                        payload: [],
                        checksum: 'checksum-status',
                        tags: [],
                    ),
                ];
            }

            public function openCursor(): ReplayReadCursor
            {
                return ReplayReadCursor::start();
            }
        };

        return new WorkerReplayCheckpointManager(
            artifactStore: $artifactStore,
            checkpointRepository: new ReplayCheckpointRepository($checkpointStore),
            logger: new NullLogger(),
            enabled: true,
        );
    }

    private function disabledCheckpointManager(): WorkerReplayCheckpointManager
    {
        return new WorkerReplayCheckpointManager(
            artifactStore: new class implements ReplayArtifactStoreInterface {
                public function write(\Apntalk\EslReplay\Artifact\CapturedArtifactEnvelope $artifact): ReplayRecordId
                {
                    throw new \BadMethodCallException('write() should not be called in this status command test.');
                }

                public function readById(ReplayRecordId $id): ?StoredReplayRecord
                {
                    return null;
                }

                public function readFromCursor(ReplayReadCursor $cursor, int $limit = 100, ?ReplayReadCriteria $criteria = null): array
                {
                    return [];
                }

                public function openCursor(): ReplayReadCursor
                {
                    return ReplayReadCursor::start();
                }
            },
            checkpointRepository: new ReplayCheckpointRepository(new class implements ReplayCheckpointStoreInterface {
                public function save(ReplayCheckpoint $checkpoint): void
                {
                }

                public function load(string $key): ?ReplayCheckpoint
                {
                    return null;
                }

                public function exists(string $key): bool
                {
                    return false;
                }

                public function delete(string $key): void
                {
                }

                public function find(\Apntalk\EslReplay\Checkpoint\ReplayCheckpointCriteria $criteria): array
                {
                    return [];
                }
            }),
            logger: new NullLogger(),
            enabled: false,
        );
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
            cluster: 'default',
            region: 'sg-1',
            healthStatus: 'healthy',
        );
    }
}
