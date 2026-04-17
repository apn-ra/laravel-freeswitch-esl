<?php

namespace ApnTalk\LaravelFreeswitchEsl\Tests\Integration\Console;

use Apntalk\EslReplay\Checkpoint\ReplayCheckpoint;
use Apntalk\EslReplay\Checkpoint\ReplayCheckpointRepository;
use Apntalk\EslReplay\Contracts\ReplayArtifactStoreInterface;
use Apntalk\EslReplay\Contracts\ReplayCheckpointInspectorInterface;
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

class FreeSwitchWorkerCheckpointStatusCommandTest extends TestCase
{
    public function test_worker_checkpoint_status_command_is_registered_with_the_console_kernel(): void
    {
        /** @var Kernel $kernel */
        $kernel = $this->app->make(Kernel::class);

        $this->assertArrayHasKey('freeswitch:worker:checkpoint-status', $kernel->all());
    }

    public function test_worker_checkpoint_status_command_reports_latest_historical_scope_summary(): void
    {
        $node = $this->makeNode(1, 'primary-fs');
        $latestSavedAt = new \DateTimeImmutable('-1 hour', new \DateTimeZone('UTC'));
        $previousSavedAt = new \DateTimeImmutable('-2 hours', new \DateTimeZone('UTC'));
        $this->bindRuntimeDependencies(
            registry: $this->registryForNodes([$node]),
            assignmentResolver: new class ($node) implements WorkerAssignmentResolverInterface {
                public function __construct(private readonly PbxNode $node) {}
                public function resolveNodes(WorkerAssignment $assignment): array { return [$this->node]; }
                public function resolveForWorkerName(string $workerName): array { return []; }
            },
            runtimeRunner: new class implements RuntimeRunnerInterface {
                public int $runCalls = 0;
                public function run(RuntimeHandoffInterface $handoff): void { $this->runCalls++; }
            },
            checkpointManager: $this->checkpointManager($latestSavedAt, $previousSavedAt),
        );

        /** @var Kernel $kernel */
        $kernel = $this->app->make(Kernel::class);
        $exitCode = $kernel->call('freeswitch:worker:checkpoint-status', [
            '--worker' => ['history-worker'],
            '--pbx' => 'primary-fs',
        ]);

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode(trim($kernel->output()), true);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($decoded);
        $this->assertSame('worker_checkpoint_history', $decoded['report_surface']);
        $this->assertFalse($decoded['live_recovery_supported']);
        $this->assertSame(24, $decoded['window_hours']);
        $this->assertSame(5, $decoded['history_limit']);
        $this->assertFalse($decoded['history_included']);
        $this->assertSame(1, $decoded['scope_count']);
        $this->assertSame([
            'provider' => null,
            'pbx_node' => null,
            'connection_profile' => null,
            'reason' => null,
        ], $decoded['filters']);
        $this->assertFalse($decoded['historical_retention_supported']);
        $this->assertNull($decoded['historical_retention_store_driver']);
        $this->assertSame(7, $decoded['historical_retention_days']);
        $this->assertFalse($decoded['historical_retention_storage_path_present']);
        $this->assertSame('requires_filesystem_replay_store', $decoded['historical_retention_basis']);
        $this->assertNull($decoded['historical_retention_support_path']);
        $this->assertSame('apntalk/esl-replay', $decoded['historical_retention_support_source']);
        $this->assertSame(24, $decoded['historical_retention_window_hours']);
        $this->assertSame([
            'limit' => 25,
            'offset' => 0,
            'returned_scope_count' => 1,
            'has_more' => false,
        ], $decoded['pagination']);
        $this->assertSame('history-worker', $decoded['scopes'][0]['worker_name']);
        $this->assertSame('node', $decoded['scopes'][0]['assignment_mode']);
        $this->assertSame(1, $decoded['scopes'][0]['scope_count']);
        $scope = $decoded['scopes'][0]['scopes'][0];
        $this->assertSame('freeswitch', $scope['provider_code']);
        $this->assertSame('primary-fs', $scope['pbx_node_slug']);
        $this->assertSame('default', $scope['connection_profile_name']);
        $this->assertSame('worker-runtime.history-worker.freeswitch.primary-fs.default', $scope['checkpoint_key']);
        $this->assertSame($latestSavedAt->format(\DateTimeInterface::RFC3339_EXTENDED), $scope['latest_checkpoint_saved_at']);
        $this->assertSame('drain-completed', $scope['latest_checkpoint_reason']);
        $this->assertSame('replay-session-hist', $scope['latest_checkpoint_replay_session_id']);
        $this->assertSame('worker-session-hist-2', $scope['latest_checkpoint_worker_session_id']);
        $this->assertSame('job-hist', $scope['latest_checkpoint_job_uuid']);
        $this->assertSame('primary-fs', $scope['latest_checkpoint_pbx_node_slug']);
        $this->assertSame('completed', $scope['latest_drain_terminal_state']);
        $this->assertSame('2026-04-18T02:55:00.000+00:00', $scope['latest_drain_started_at']);
        $this->assertSame('2026-04-18T02:55:30.000+00:00', $scope['latest_drain_deadline_at']);
        $this->assertSame(2, $scope['checkpoint_count_in_window']);
        $this->assertSame($previousSavedAt->format(\DateTimeInterface::RFC3339_EXTENDED), $scope['oldest_checkpoint_saved_at_in_window']);
        $this->assertSame($latestSavedAt->format(\DateTimeInterface::RFC3339_EXTENDED), $scope['newest_checkpoint_saved_at_in_window']);
        $this->assertFalse($scope['historical_pruning_supported']);
        $this->assertNull($scope['historical_pruning_candidate_count']);
        $this->assertSame(24, $scope['historical_pruning_window_hours']);
        $this->assertSame('requires_filesystem_replay_store', $scope['historical_pruning_basis']);
        $this->assertSame([], $scope['history']);
    }

    public function test_worker_checkpoint_status_command_can_include_bounded_history_entries(): void
    {
        $node = $this->makeNode(1, 'primary-fs');
        $latestSavedAt = new \DateTimeImmutable('-1 hour', new \DateTimeZone('UTC'));
        $previousSavedAt = new \DateTimeImmutable('-2 hours', new \DateTimeZone('UTC'));
        $this->bindRuntimeDependencies(
            registry: $this->registryForNodes([$node]),
            assignmentResolver: new class ($node) implements WorkerAssignmentResolverInterface {
                public function __construct(private readonly PbxNode $node) {}
                public function resolveNodes(WorkerAssignment $assignment): array { return [$this->node]; }
                public function resolveForWorkerName(string $workerName): array { return []; }
            },
            runtimeRunner: new class implements RuntimeRunnerInterface {
                public function run(RuntimeHandoffInterface $handoff): void {}
            },
            checkpointManager: $this->checkpointManager($latestSavedAt, $previousSavedAt),
        );

        /** @var Kernel $kernel */
        $kernel = $this->app->make(Kernel::class);
        $exitCode = $kernel->call('freeswitch:worker:checkpoint-status', [
            '--worker' => ['history-worker'],
            '--pbx' => 'primary-fs',
            '--include-history' => true,
            '--history-limit' => 2,
            '--window-hours' => 12,
        ]);

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode(trim($kernel->output()), true);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($decoded);
        $scope = $decoded['scopes'][0]['scopes'][0];
        $this->assertTrue($decoded['history_included']);
        $this->assertSame(2, $decoded['history_limit']);
        $this->assertCount(2, $scope['history']);
        $this->assertSame('drain-completed', $scope['history'][0]['reason']);
        $this->assertSame('completed', $scope['history'][0]['drain_terminal_state']);
        $this->assertSame('drain-requested', $scope['history'][1]['reason']);
        $this->assertSame('none', $scope['history'][1]['drain_terminal_state']);
    }

    public function test_worker_checkpoint_status_command_reports_bounded_historical_pruning_posture_when_supported(): void
    {
        $node = $this->makeNode(1, 'primary-fs');
        $storagePath = sys_get_temp_dir() . '/laravel-freeswitch-esl-tests/checkpoint-status-pruning-' . bin2hex(random_bytes(4));
        @mkdir($storagePath, 0777, true);

        try {
            $artifactStore = new \Apntalk\EslReplay\Adapter\Filesystem\FilesystemReplayArtifactStore($storagePath);
            $checkpointStore = new class implements ReplayCheckpointStoreInterface, ReplayCheckpointInspectorInterface {
                public function save(ReplayCheckpoint $checkpoint): void {}
                public function load(string $key): ?ReplayCheckpoint
                {
                    return new ReplayCheckpoint(
                        key: $key,
                        cursor: new ReplayReadCursor(2, 20),
                        savedAt: new \DateTimeImmutable('-30 minutes', new \DateTimeZone('UTC')),
                        metadata: [
                            'checkpoint_reason' => 'drain-requested',
                            'replay_session_id' => 'replay-session-prune',
                            'worker_session_id' => 'worker-session-prune',
                            'job_uuid' => 'job-prune',
                            'pbx_node_slug' => 'primary-fs',
                        ],
                    );
                }
                public function exists(string $key): bool { return true; }
                public function delete(string $key): void {}
                public function find(\Apntalk\EslReplay\Checkpoint\ReplayCheckpointCriteria $criteria): array
                {
                    return [
                        new ReplayCheckpoint(
                            key: 'worker-runtime.history-worker.freeswitch.primary-fs.default',
                            cursor: new ReplayReadCursor(2, 20),
                            savedAt: new \DateTimeImmutable('-30 minutes', new \DateTimeZone('UTC')),
                            metadata: [
                                'checkpoint_reason' => 'drain-requested',
                                'replay_session_id' => 'replay-session-prune',
                                'worker_session_id' => 'worker-session-prune',
                                'job_uuid' => 'job-prune',
                                'pbx_node_slug' => 'primary-fs',
                            ],
                        ),
                    ];
                }
            };

            $artifactStore->write(new \ApnTalk\LaravelFreeswitchEsl\Integration\Replay\ReplayEnvelopeArtifactAdapter(
                $this->fixtureEnvelope('replay-session-prune', 1_715_702_400_000_000),
                $this->fixtureContext('primary-fs', 'worker-session-prune'),
            ));
            $artifactStore->write(new \ApnTalk\LaravelFreeswitchEsl\Integration\Replay\ReplayEnvelopeArtifactAdapter(
                $this->fixtureEnvelope('replay-session-prune', 1_715_788_800_000_000),
                $this->fixtureContext('primary-fs', 'worker-session-prune'),
            ));
            $artifactStore->write(new \ApnTalk\LaravelFreeswitchEsl\Integration\Replay\ReplayEnvelopeArtifactAdapter(
                $this->fixtureEnvelope('replay-session-prune', 1_766_145_600_000_000),
                $this->fixtureContext('primary-fs', 'worker-session-prune'),
            ));

            $this->bindRuntimeDependencies(
                registry: $this->registryForNodes([$node]),
                assignmentResolver: new class ($node) implements WorkerAssignmentResolverInterface {
                    public function __construct(private readonly PbxNode $node) {}
                    public function resolveNodes(WorkerAssignment $assignment): array { return [$this->node]; }
                    public function resolveForWorkerName(string $workerName): array { return []; }
                },
                runtimeRunner: new class implements RuntimeRunnerInterface {
                    public function run(RuntimeHandoffInterface $handoff): void {}
                },
                checkpointManager: new WorkerReplayCheckpointManager(
                    artifactStore: $artifactStore,
                    checkpointRepository: new ReplayCheckpointRepository($checkpointStore),
                    logger: new NullLogger(),
                    enabled: true,
                    replayStoreDriver: 'filesystem',
                    replayStoragePath: $storagePath,
                    retentionDays: 7,
                ),
            );

            /** @var Kernel $kernel */
            $kernel = $this->app->make(Kernel::class);
            $exitCode = $kernel->call('freeswitch:worker:checkpoint-status', [
                '--worker' => ['history-worker'],
                '--pbx' => 'primary-fs',
            ]);

            /** @var array<string, mixed>|null $decoded */
            $decoded = json_decode(trim($kernel->output()), true);

            $this->assertSame(0, $exitCode);
            $this->assertIsArray($decoded);
            $this->assertTrue($decoded['historical_retention_supported']);
            $this->assertSame('filesystem', $decoded['historical_retention_store_driver']);
            $this->assertSame(7, $decoded['historical_retention_days']);
            $this->assertTrue($decoded['historical_retention_storage_path_present']);
            $this->assertSame('configured_filesystem_retention_policy', $decoded['historical_retention_basis']);
            $this->assertSame('checkpoint_aware_pruner', $decoded['historical_retention_support_path']);
            $this->assertSame('apntalk/esl-replay', $decoded['historical_retention_support_source']);
            $this->assertSame(24, $decoded['historical_retention_window_hours']);
            $scope = $decoded['scopes'][0]['scopes'][0];
            $this->assertTrue($scope['historical_pruning_supported']);
            $this->assertSame(2, $scope['historical_pruning_candidate_count']);
            $this->assertSame(24, $scope['historical_pruning_window_hours']);
            $this->assertSame('filesystem_retention_plan', $scope['historical_pruning_basis']);
        } finally {
            $file = $storagePath . '/artifacts.ndjson';
            if (file_exists($file)) {
                @unlink($file);
            }
            @rmdir($storagePath);
        }
    }

    public function test_worker_checkpoint_status_command_can_report_multiple_db_backed_workers(): void
    {
        $primary = $this->makeNode(1, 'primary-fs');
        $edge = $this->makeNode(2, 'edge-fs');

        $this->bindRuntimeDependencies(
            registry: $this->registryForNodes([$primary, $edge]),
            assignmentResolver: new class ($primary, $edge) implements WorkerAssignmentResolverInterface {
                public function __construct(private readonly PbxNode $primary, private readonly PbxNode $edge) {}
                public function resolveNodes(WorkerAssignment $assignment): array { return []; }
                public function resolveForWorkerName(string $workerName): array
                {
                    return match ($workerName) {
                        'worker-a' => [$this->primary],
                        'worker-b' => [$this->edge],
                        default => [],
                    };
                }
            },
            runtimeRunner: new class implements RuntimeRunnerInterface {
                public function run(RuntimeHandoffInterface $handoff): void {}
            },
            checkpointManager: $this->disabledCheckpointManager(),
        );

        /** @var Kernel $kernel */
        $kernel = $this->app->make(Kernel::class);
        $exitCode = $kernel->call('freeswitch:worker:checkpoint-status', [
            '--db' => true,
            '--worker' => ['worker-a', 'worker-b', 'worker-empty'],
        ]);

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode(trim($kernel->output()), true);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($decoded);
        $this->assertCount(3, $decoded['scopes']);
        $this->assertSame('worker-a', $decoded['scopes'][0]['worker_name']);
        $this->assertSame('primary-fs', $decoded['scopes'][0]['scopes'][0]['pbx_node_slug']);
        $this->assertSame('worker-b', $decoded['scopes'][1]['worker_name']);
        $this->assertSame('edge-fs', $decoded['scopes'][1]['scopes'][0]['pbx_node_slug']);
        $this->assertSame('worker-empty', $decoded['scopes'][2]['worker_name']);
        $this->assertSame([], $decoded['scopes'][2]['scopes']);
    }

    public function test_worker_checkpoint_status_command_applies_filters_and_stable_pagination(): void
    {
        $primary = $this->makeNode(1, 'primary-fs');
        $edge = $this->makeNode(2, 'edge-fs');

        $this->bindRuntimeDependencies(
            registry: $this->registryForNodes([$primary, $edge]),
            assignmentResolver: new class ($primary, $edge) implements WorkerAssignmentResolverInterface {
                public function __construct(private readonly PbxNode $primary, private readonly PbxNode $edge) {}
                public function resolveNodes(WorkerAssignment $assignment): array { return []; }
                public function resolveForWorkerName(string $workerName): array
                {
                    return match ($workerName) {
                        'worker-a' => [$this->primary],
                        'worker-b' => [$this->edge],
                        'worker-c' => [$this->primary],
                        default => [],
                    };
                }
            },
            runtimeRunner: new class implements RuntimeRunnerInterface {
                public function run(RuntimeHandoffInterface $handoff): void {}
            },
            checkpointManager: $this->filteredCheckpointManager(),
        );

        /** @var Kernel $kernel */
        $kernel = $this->app->make(Kernel::class);
        $exitCode = $kernel->call('freeswitch:worker:checkpoint-status', [
            '--db' => true,
            '--worker' => ['worker-a', 'worker-b', 'worker-c'],
            '--provider' => 'freeswitch',
            '--pbx-node' => 'primary-fs',
            '--reason' => 'drain-completed',
            '--limit' => 1,
            '--offset' => 1,
        ]);

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode(trim($kernel->output()), true);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($decoded);
        $this->assertSame([
            'provider' => 'freeswitch',
            'pbx_node' => 'primary-fs',
            'connection_profile' => null,
            'reason' => 'drain-completed',
        ], $decoded['filters']);
        $this->assertSame([
            'limit' => 1,
            'offset' => 1,
            'returned_scope_count' => 1,
            'has_more' => false,
        ], $decoded['pagination']);
        $this->assertCount(1, $decoded['scopes']);
        $this->assertSame('worker-c', $decoded['scopes'][0]['worker_name']);
        $this->assertSame('primary-fs', $decoded['scopes'][0]['scopes'][0]['pbx_node_slug']);
        $this->assertSame('drain-completed', $decoded['scopes'][0]['scopes'][0]['latest_checkpoint_reason']);
    }

    /**
     * @param  list<PbxNode>  $nodes
     */
    private function registryForNodes(array $nodes): PbxRegistryInterface
    {
        return new class ($nodes) implements PbxRegistryInterface {
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
            public function allActive(): array { return $this->nodes; }
            public function allByCluster(string $cluster): array { return $this->nodes; }
            public function allByTags(array $tags): array { return $this->nodes; }
            public function allByProvider(string $providerCode): array { return $this->nodes; }
        };
    }

    private function bindRuntimeDependencies(
        PbxRegistryInterface $registry,
        WorkerAssignmentResolverInterface $assignmentResolver,
        RuntimeRunnerInterface $runtimeRunner,
        WorkerReplayCheckpointManager $checkpointManager,
    ): void {
        $connectionResolver = new class implements ConnectionResolverInterface {
            public function resolveForNode(int $pbxNodeId): ConnectionContext { return $this->context('primary-fs', $pbxNodeId); }
            public function resolveForSlug(string $slug): ConnectionContext { return $this->context($slug, $slug === 'edge-fs' ? 2 : 1); }
            public function resolveForPbxNode(PbxNode $node): ConnectionContext { return $this->context($node->slug, $node->id); }
            private function context(string $slug, int $id): ConnectionContext
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
        \DateTimeImmutable $latestSavedAt,
        \DateTimeImmutable $previousSavedAt,
    ): WorkerReplayCheckpointManager
    {
        $checkpointStore = new class ($latestSavedAt, $previousSavedAt) implements ReplayCheckpointStoreInterface, ReplayCheckpointInspectorInterface {
            public function __construct(
                private readonly \DateTimeImmutable $latestSavedAt,
                private readonly \DateTimeImmutable $previousSavedAt,
            ) {}
            public function save(ReplayCheckpoint $checkpoint): void {}
            public function load(string $key): ?ReplayCheckpoint
            {
                return new ReplayCheckpoint(
                    key: $key,
                    cursor: new ReplayReadCursor(9, 90),
                    savedAt: $this->latestSavedAt,
                    metadata: [
                        'checkpoint_reason' => 'drain-completed',
                        'replay_session_id' => 'replay-session-hist',
                        'worker_session_id' => 'worker-session-hist-2',
                        'job_uuid' => 'job-hist',
                        'pbx_node_slug' => 'primary-fs',
                        'drain_started_at' => '2026-04-18T02:55:00.000+00:00',
                        'drain_deadline_at' => '2026-04-18T02:55:30.000+00:00',
                    ],
                );
            }
            public function exists(string $key): bool { return true; }
            public function delete(string $key): void {}
            public function find(\Apntalk\EslReplay\Checkpoint\ReplayCheckpointCriteria $criteria): array
            {
                return [
                    new ReplayCheckpoint(
                        key: 'worker-runtime.history-worker.freeswitch.primary-fs.default',
                        cursor: new ReplayReadCursor(9, 90),
                        savedAt: $this->latestSavedAt,
                        metadata: [
                            'checkpoint_reason' => 'drain-completed',
                            'replay_session_id' => 'replay-session-hist',
                            'worker_session_id' => 'worker-session-hist-2',
                            'job_uuid' => 'job-hist',
                            'pbx_node_slug' => 'primary-fs',
                            'drain_started_at' => '2026-04-18T02:55:00.000+00:00',
                            'drain_deadline_at' => '2026-04-18T02:55:30.000+00:00',
                        ],
                    ),
                    new ReplayCheckpoint(
                        key: 'worker-runtime.history-worker.freeswitch.primary-fs.default',
                        cursor: new ReplayReadCursor(8, 80),
                        savedAt: $this->previousSavedAt,
                        metadata: [
                            'checkpoint_reason' => 'drain-requested',
                            'replay_session_id' => 'replay-session-hist',
                            'worker_session_id' => 'worker-session-hist-1',
                            'job_uuid' => 'job-hist',
                            'pbx_node_slug' => 'primary-fs',
                            'drain_started_at' => '2026-04-18T01:59:00.000+00:00',
                            'drain_deadline_at' => '2026-04-18T01:59:30.000+00:00',
                        ],
                    ),
                ];
            }
        };

        $artifactStore = new class implements ReplayArtifactStoreInterface {
            public function write(\Apntalk\EslReplay\Artifact\CapturedArtifactEnvelope $artifact): ReplayRecordId
            {
                throw new \BadMethodCallException('write() should not be called in this checkpoint status command test.');
            }
            public function readById(ReplayRecordId $id): ?StoredReplayRecord { return null; }
            public function readFromCursor(ReplayReadCursor $cursor, int $limit = 100, ?ReplayReadCriteria $criteria = null): array { return []; }
            public function openCursor(): ReplayReadCursor { return ReplayReadCursor::start(); }
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
                public function write(\Apntalk\EslReplay\Artifact\CapturedArtifactEnvelope $artifact): ReplayRecordId { throw new \BadMethodCallException('write() should not be called in this checkpoint status command test.'); }
                public function readById(ReplayRecordId $id): ?StoredReplayRecord { return null; }
                public function readFromCursor(ReplayReadCursor $cursor, int $limit = 100, ?ReplayReadCriteria $criteria = null): array { return []; }
                public function openCursor(): ReplayReadCursor { return ReplayReadCursor::start(); }
            },
            checkpointRepository: new ReplayCheckpointRepository(new class implements ReplayCheckpointStoreInterface {
                public function save(ReplayCheckpoint $checkpoint): void {}
                public function load(string $key): ?ReplayCheckpoint { return null; }
                public function exists(string $key): bool { return false; }
                public function delete(string $key): void {}
                public function find(\Apntalk\EslReplay\Checkpoint\ReplayCheckpointCriteria $criteria): array { return []; }
            }),
            logger: new NullLogger(),
            enabled: false,
        );
    }

    private function filteredCheckpointManager(): WorkerReplayCheckpointManager
    {
        $checkpointStore = new class implements ReplayCheckpointStoreInterface, ReplayCheckpointInspectorInterface {
            public function save(ReplayCheckpoint $checkpoint): void {}
            public function load(string $key): ?ReplayCheckpoint
            {
                $slug = str_contains($key, '.edge-fs.') ? 'edge-fs' : 'primary-fs';
                $reason = str_contains($key, 'worker-b') ? 'drain-timeout' : 'drain-completed';

                return new ReplayCheckpoint(
                    key: $key,
                    cursor: new ReplayReadCursor(1, 10),
                    savedAt: new \DateTimeImmutable('-1 hour', new \DateTimeZone('UTC')),
                    metadata: [
                        'checkpoint_reason' => $reason,
                        'replay_session_id' => 'replay-session-filter',
                        'worker_session_id' => 'worker-session-filter',
                        'job_uuid' => 'job-filter',
                        'pbx_node_slug' => $slug,
                    ],
                );
            }
            public function exists(string $key): bool { return true; }
            public function delete(string $key): void {}
            public function find(\Apntalk\EslReplay\Checkpoint\ReplayCheckpointCriteria $criteria): array
            {
                $pbxNodeSlug = $criteria->pbxNodeSlug ?? 'primary-fs';

                return [
                    new ReplayCheckpoint(
                        key: sprintf('worker-runtime.synthetic.freeswitch.%s.default', $pbxNodeSlug),
                        cursor: new ReplayReadCursor(1, 10),
                        savedAt: new \DateTimeImmutable('-1 hour', new \DateTimeZone('UTC')),
                        metadata: [
                            'checkpoint_reason' => $pbxNodeSlug === 'edge-fs' ? 'drain-timeout' : 'drain-completed',
                            'replay_session_id' => 'replay-session-filter',
                            'worker_session_id' => 'worker-session-filter',
                            'job_uuid' => 'job-filter',
                            'pbx_node_slug' => $pbxNodeSlug,
                        ],
                    ),
                ];
            }
        };

        return new WorkerReplayCheckpointManager(
            artifactStore: new class implements ReplayArtifactStoreInterface {
                public function write(\Apntalk\EslReplay\Artifact\CapturedArtifactEnvelope $artifact): ReplayRecordId { throw new \BadMethodCallException('write() should not be called in this checkpoint status command test.'); }
                public function readById(ReplayRecordId $id): ?StoredReplayRecord { return null; }
                public function readFromCursor(ReplayReadCursor $cursor, int $limit = 100, ?ReplayReadCriteria $criteria = null): array { return []; }
                public function openCursor(): ReplayReadCursor { return ReplayReadCursor::start(); }
            },
            checkpointRepository: new ReplayCheckpointRepository($checkpointStore),
            logger: new NullLogger(),
            enabled: true,
        );
    }

    private function fixtureContext(string $pbxNodeSlug, string $workerSessionId): ConnectionContext
    {
        return new ConnectionContext(
            pbxNodeId: $pbxNodeSlug === 'edge-fs' ? 2 : 1,
            pbxNodeSlug: $pbxNodeSlug,
            providerCode: 'freeswitch',
            host: '127.0.0.1',
            port: 8021,
            username: '',
            resolvedPassword: 'ClueCon',
            transport: 'tcp',
            connectionProfileId: null,
            connectionProfileName: 'default',
            workerSessionId: $workerSessionId,
        );
    }

    private function fixtureEnvelope(string $sessionId, int $capturedAtMicros): \Apntalk\EslCore\Contracts\ReplayEnvelopeInterface
    {
        return new class ($sessionId, $capturedAtMicros) implements \Apntalk\EslCore\Contracts\ReplayEnvelopeInterface {
            public function __construct(
                private readonly string $sessionId,
                private readonly int $capturedAtMicros,
            ) {}

            public function capturedType(): string { return 'event'; }
            public function capturedName(): string { return 'CHANNEL_CREATE'; }
            public function sessionId(): ?string { return $this->sessionId; }
            public function captureSequence(): int { return 1; }
            public function capturedAtMicros(): int { return $this->capturedAtMicros; }
            public function protocolSequence(): ?string { return '42'; }
            public function rawPayload(): string { return 'Event-Name: CHANNEL_CREATE'; }
            public function classifierContext(): array { return ['content-type' => 'text/event-plain']; }
            public function protocolFacts(): array { return ['event-name' => 'CHANNEL_CREATE']; }
            public function derivedMetadata(): array
            {
                return [
                    'replay-artifact-version' => '1',
                    'replay-artifact-name' => 'event.raw',
                    'runtime-capture-path' => 'event.raw',
                ];
            }
        };
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
