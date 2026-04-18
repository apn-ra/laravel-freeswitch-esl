<?php

namespace ApnTalk\LaravelFreeswitchEsl\Tests\Unit\Worker;

use Apntalk\EslCore\Transport\InMemoryTransport;
use Apntalk\EslReplay\Artifact\CapturedArtifactEnvelope;
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
use ApnTalk\LaravelFreeswitchEsl\Contracts\RuntimeHandoffInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\RuntimeRunnerFeedbackProviderInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\RuntimeRunnerInterface;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\ConnectionContext;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\PbxNode;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\RuntimeRunnerFeedback;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\WorkerStatus;
use ApnTalk\LaravelFreeswitchEsl\Exceptions\WorkerException;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslCoreCommandFactory;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslCoreConnectionHandle;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslCorePipelineFactory;
use ApnTalk\LaravelFreeswitchEsl\Integration\NonLiveRuntimeRunner;
use ApnTalk\LaravelFreeswitchEsl\Integration\Replay\WorkerReplayCheckpointManager;
use ApnTalk\LaravelFreeswitchEsl\Worker\WorkerRuntime;
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
        $this->assertSame('not-observed-by-laravel', $status->meta['runtime_loop_active_source']);
        $this->assertFalse($status->meta['runtime_feedback_observed']);
        $this->assertNull($status->meta['runtime_feedback_delivery']);
        $this->assertFalse($status->meta['runtime_push_lifecycle_observed']);
        $this->assertNull($status->meta['runtime_runner_state']);
        $this->assertFalse($status->isHandoffPrepared());
        $this->assertFalse($status->isRuntimeRunnerInvoked());
        $this->assertFalse($status->isRuntimeFeedbackObserved());
        $this->assertFalse($status->isRuntimePushObserved());
        $this->assertFalse($status->isRuntimeLoopActive());
        $this->assertFalse($status->meta['checkpoint_enabled']);
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
        $this->assertSame('not-observed-by-laravel', $status->meta['runtime_loop_active_source']);
        $this->assertFalse($status->meta['runtime_feedback_observed']);
        $this->assertNull($status->meta['runtime_feedback_delivery']);
        $this->assertFalse($status->meta['runtime_push_lifecycle_observed']);
        $this->assertNull($status->meta['runtime_runner_state']);
        $this->assertTrue($status->isHandoffPrepared());
        $this->assertFalse($status->isRuntimeRunnerInvoked());
        $this->assertFalse($status->isRuntimeFeedbackObserved());
        $this->assertFalse($status->isRuntimePushObserved());
        $this->assertFalse($status->isRuntimeLoopActive());
    }

    public function test_boot_surfaces_resuming_checkpoint_state_when_existing_checkpoint_loaded(): void
    {
        $checkpointStore = new class implements ReplayCheckpointStoreInterface
        {
            public function save(ReplayCheckpoint $checkpoint): void {}

            public function load(string $key): ?ReplayCheckpoint
            {
                return new ReplayCheckpoint(
                    key: $key,
                    cursor: new ReplayReadCursor(12, 144),
                    savedAt: new \DateTimeImmutable('2026-04-17T12:00:00+00:00'),
                    metadata: ['worker_session_id' => 'previous-session'],
                );
            }

            public function exists(string $key): bool
            {
                return true;
            }

            public function delete(string $key): void {}
        };

        $runtime = $this->makeRuntime(
            checkpointManager: new WorkerReplayCheckpointManager(
                artifactStore: $this->emptyArtifactStore(),
                checkpointRepository: new ReplayCheckpointRepository($checkpointStore),
                logger: new NullLogger,
                enabled: true,
            ),
        );

        $runtime->boot();
        $status = $runtime->status();

        $this->assertTrue($status->meta['checkpoint_enabled']);
        $this->assertTrue($status->meta['checkpoint_is_resuming']);
        $this->assertSame(12, $status->meta['checkpoint_last_consumed_sequence']);
        $this->assertSame('previous-session', $status->meta['checkpoint_metadata']['worker_session_id']);
        $this->assertTrue($status->meta['checkpoint_recovery_supported']);
        $this->assertSame('previous-session', $status->meta['checkpoint_recovery_worker_session_id']);
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

    public function test_run_after_boot_marks_non_live_runner_feedback_without_marking_runtime_active(): void
    {
        $runtime = $this->makeRuntime();

        $runtime->boot();
        $runtime->run();
        $status = $runtime->status();

        $this->assertTrue($status->meta['runtime_runner_invoked']);
        $this->assertTrue($status->isRuntimeRunnerInvoked());
        $this->assertTrue($status->meta['runtime_feedback_observed']);
        $this->assertSame('snapshot', $status->meta['runtime_feedback_delivery']);
        $this->assertFalse($status->meta['runtime_push_lifecycle_observed']);
        $this->assertTrue($status->isRuntimeFeedbackObserved());
        $this->assertFalse($status->isRuntimePushObserved());
        $this->assertSame(RuntimeRunnerFeedback::STATE_NOT_LIVE, $status->meta['runtime_runner_state']);
        $this->assertSame('non-live-runtime-runner', $status->meta['runtime_feedback_source']);
        $this->assertSame('non-live-runtime-runner', $status->meta['runtime_loop_active_source']);
        $this->assertNull($status->meta['runtime_status_phase']);
        $this->assertNull($status->meta['runtime_active']);
        $this->assertNull($status->meta['runtime_recovery_in_progress']);
        $this->assertFalse($status->meta['runtime_loop_active']);
        $this->assertFalse($status->isRuntimeLoopActive());
    }

    public function test_run_after_boot_marks_runtime_active_when_runner_feedback_reports_running(): void
    {
        $runtime = $this->makeRuntime(new class implements RuntimeRunnerFeedbackProviderInterface, RuntimeRunnerInterface
        {
            private ?RuntimeRunnerFeedback $feedback = null;

            public function run(RuntimeHandoffInterface $handoff): void
            {
                $this->feedback = new RuntimeRunnerFeedback(
                    state: RuntimeRunnerFeedback::STATE_RUNNING,
                    source: 'test-runner-handle',
                    delivery: 'push',
                    endpoint: $handoff->endpoint(),
                    sessionId: $handoff->context()->workerSessionId,
                );
            }

            public function runtimeFeedback(): ?RuntimeRunnerFeedback
            {
                return $this->feedback;
            }
        });

        $runtime->boot();
        $runtime->run();
        $status = $runtime->status();

        $this->assertTrue($status->meta['runtime_feedback_observed']);
        $this->assertSame('push', $status->meta['runtime_feedback_delivery']);
        $this->assertTrue($status->meta['runtime_push_lifecycle_observed']);
        $this->assertSame(RuntimeRunnerFeedback::STATE_RUNNING, $status->meta['runtime_runner_state']);
        $this->assertSame('test-runner-handle', $status->meta['runtime_feedback_source']);
        $this->assertTrue($status->isRuntimePushObserved());
        $this->assertNull($status->meta['runtime_status_phase']);
        $this->assertTrue($status->meta['runtime_loop_active']);
        $this->assertTrue($status->isRuntimeLoopActive());
        $this->assertSame($status->sessionId, $status->meta['runtime_runner_session_id']);
    }

    public function test_status_uses_runtime_owned_reconnecting_phase_when_feedback_reports_recovery_in_progress(): void
    {
        $runtime = $this->makeRuntime(new class implements RuntimeRunnerFeedbackProviderInterface, RuntimeRunnerInterface
        {
            private ?RuntimeRunnerFeedback $feedback = null;

            public function run(RuntimeHandoffInterface $handoff): void
            {
                $this->feedback = new RuntimeRunnerFeedback(
                    state: RuntimeRunnerFeedback::STATE_RUNNING,
                    source: 'status-snapshot-runner',
                    delivery: 'push',
                    statusPhase: 'reconnecting',
                    endpoint: $handoff->endpoint(),
                    sessionId: $handoff->context()->workerSessionId,
                    isRuntimeActive: true,
                    isRecoveryInProgress: true,
                    reconnectAttempts: 5,
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
        });

        $runtime->boot();
        $runtime->run();
        $status = $runtime->status();

        $this->assertSame(WorkerStatus::STATE_RECONNECTING, $status->state);
        $this->assertSame(5, $status->retryAttempt);
        $this->assertNotNull($status->lastHeartbeatAt);
        $this->assertSame('reconnecting', $status->meta['runtime_status_phase']);
        $this->assertTrue($status->meta['runtime_active']);
        $this->assertTrue($status->meta['runtime_recovery_in_progress']);
        $this->assertSame(\RuntimeException::class, $status->meta['runtime_last_disconnect_reason_class']);
        $this->assertSame('disconnect observed', $status->meta['runtime_last_disconnect_reason_message']);
        $this->assertSame(\LogicException::class, $status->meta['runtime_last_error_class']);
        $this->assertSame('failure summary', $status->meta['runtime_last_error_message']);
        $this->assertNotNull($status->meta['runtime_last_successful_connect_at']);
        $this->assertNotNull($status->meta['runtime_last_disconnect_at']);
        $this->assertNotNull($status->meta['runtime_last_failure_at']);
    }

    public function test_drain_records_checkpoint_and_completes_immediately_when_no_inflight(): void
    {
        $savedCheckpoint = null;
        $checkpointStore = new class($savedCheckpoint) implements ReplayCheckpointStoreInterface
        {
            public ?ReplayCheckpoint $savedCheckpoint = null;

            public function __construct(?ReplayCheckpoint $savedCheckpoint)
            {
                $this->savedCheckpoint = $savedCheckpoint;
            }

            public function save(ReplayCheckpoint $checkpoint): void
            {
                $this->savedCheckpoint = $checkpoint;
            }

            public function load(string $key): ?ReplayCheckpoint
            {
                return null;
            }

            public function exists(string $key): bool
            {
                return false;
            }

            public function delete(string $key): void {}
        };

        $runtime = $this->makeRuntime(
            checkpointManager: new WorkerReplayCheckpointManager(
                artifactStore: $this->emptyArtifactStore(),
                checkpointRepository: new ReplayCheckpointRepository($checkpointStore),
                logger: new NullLogger,
                enabled: true,
            ),
        );

        $runtime->boot();
        $runtime->drain();
        $status = $runtime->status();

        $this->assertTrue($status->isDraining());
        $this->assertTrue($status->meta['drain_completed']);
        $this->assertFalse($status->meta['drain_timed_out']);
        $this->assertTrue($status->meta['checkpoint_saved']);
        $this->assertSame('drain-completed', $status->meta['checkpoint_reason']);
        $this->assertSame(0, $status->meta['checkpoint_last_consumed_sequence']);
        $this->assertInstanceOf(ReplayCheckpoint::class, $checkpointStore->savedCheckpoint);
        $this->assertSame('drain-completed', $checkpointStore->savedCheckpoint->metadata['checkpoint_reason']);
    }

    public function test_drain_waits_for_inflight_work_until_completion(): void
    {
        $runtime = $this->makeRuntime();

        $runtime->boot();
        $runtime->beginInflightWork(2);
        $runtime->drain();

        $this->assertFalse($runtime->status()->meta['drain_completed']);
        $this->assertSame(2, $runtime->status()->meta['drain_waiting_on_inflight']);

        $runtime->completeInflightWork();

        $this->assertFalse($runtime->status()->meta['drain_completed']);
        $this->assertSame(1, $runtime->status()->meta['drain_waiting_on_inflight']);

        $runtime->completeInflightWork();

        $this->assertTrue($runtime->status()->meta['drain_completed']);
        $this->assertSame(0, $runtime->status()->meta['drain_waiting_on_inflight']);
    }

    public function test_drain_times_out_when_inflight_work_does_not_finish_before_deadline(): void
    {
        $runtime = $this->makeRuntime(drainTimeoutMilliseconds: 0);

        $runtime->boot();
        $runtime->beginInflightWork();
        $runtime->drain();
        $status = $runtime->status();

        $this->assertTrue($status->meta['drain_completed']);
        $this->assertTrue($status->meta['drain_timed_out']);
    }

    public function test_periodic_checkpoint_saves_only_after_interval_elapses_and_tracks_last_periodic_timestamp(): void
    {
        $checkpointStore = new class implements ReplayCheckpointStoreInterface
        {
            /** @var list<ReplayCheckpoint> */
            public array $saved = [];

            public function save(ReplayCheckpoint $checkpoint): void
            {
                $this->saved[] = $checkpoint;
            }

            public function load(string $key): ?ReplayCheckpoint
            {
                return null;
            }

            public function exists(string $key): bool
            {
                return false;
            }

            public function delete(string $key): void {}
        };

        $clock = new TestClock('2026-04-18T12:00:00+00:00');

        $runtime = $this->makeRuntime(
            checkpointManager: new WorkerReplayCheckpointManager(
                artifactStore: $this->emptyArtifactStore(),
                checkpointRepository: new ReplayCheckpointRepository($checkpointStore),
                logger: new NullLogger,
                enabled: true,
            ),
            checkpointIntervalSeconds: 60,
            clock: $clock,
        );

        $runtime->boot();
        $runtime->run();

        $this->assertCount(0, $checkpointStore->saved);
        $this->assertNull($runtime->status()->meta['checkpoint_periodic_last_saved_at']);
        $this->assertCount(0, $checkpointStore->saved);

        $clock->advance('+61 seconds');
        $status = $runtime->status();

        $this->assertCount(1, $checkpointStore->saved);
        $this->assertSame('periodic', $status->meta['checkpoint_reason']);
        $this->assertSame(60, $status->meta['checkpoint_periodic_interval_seconds']);
        $this->assertSame('2026-04-18T12:01:01.000+00:00', $status->meta['checkpoint_periodic_last_saved_at']);
        $this->assertSame('periodic', $checkpointStore->saved[0]->metadata['checkpoint_reason']);

        $runtime->status();
        $this->assertCount(1, $checkpointStore->saved);
    }

    public function test_periodic_checkpoint_is_not_saved_before_runtime_runner_invocation(): void
    {
        $checkpointStore = new class implements ReplayCheckpointStoreInterface
        {
            /** @var list<ReplayCheckpoint> */
            public array $saved = [];

            public function save(ReplayCheckpoint $checkpoint): void
            {
                $this->saved[] = $checkpoint;
            }

            public function load(string $key): ?ReplayCheckpoint
            {
                return null;
            }

            public function exists(string $key): bool
            {
                return false;
            }

            public function delete(string $key): void {}
        };

        $clock = new TestClock('2026-04-18T12:00:00+00:00');

        $runtime = $this->makeRuntime(
            checkpointManager: new WorkerReplayCheckpointManager(
                artifactStore: $this->emptyArtifactStore(),
                checkpointRepository: new ReplayCheckpointRepository($checkpointStore),
                logger: new NullLogger,
                enabled: true,
            ),
            checkpointIntervalSeconds: 60,
            clock: $clock,
        );

        $runtime->boot();
        $clock->advance('+2 minutes');
        $status = $runtime->status();

        $this->assertCount(0, $checkpointStore->saved);
        $this->assertNull($status->meta['checkpoint_periodic_last_saved_at']);
    }

    public function test_periodic_checkpoint_does_not_override_terminal_drain_checkpoint_reason(): void
    {
        $checkpointStore = new class implements ReplayCheckpointStoreInterface
        {
            /** @var list<ReplayCheckpoint> */
            public array $saved = [];

            public function save(ReplayCheckpoint $checkpoint): void
            {
                $this->saved[] = $checkpoint;
            }

            public function load(string $key): ?ReplayCheckpoint
            {
                return null;
            }

            public function exists(string $key): bool
            {
                return false;
            }

            public function delete(string $key): void {}
        };

        $clock = new TestClock('2026-04-18T12:00:00+00:00');

        $runtime = $this->makeRuntime(
            checkpointManager: new WorkerReplayCheckpointManager(
                artifactStore: $this->emptyArtifactStore(),
                checkpointRepository: new ReplayCheckpointRepository($checkpointStore),
                logger: new NullLogger,
                enabled: true,
            ),
            checkpointIntervalSeconds: 60,
            clock: $clock,
        );

        $runtime->boot();
        $runtime->run();
        $clock->advance('+61 seconds');
        $runtime->status();

        $this->assertCount(1, $checkpointStore->saved);
        $this->assertSame('periodic', $checkpointStore->saved[0]->metadata['checkpoint_reason']);

        $runtime->drain();
        $status = $runtime->status();

        $this->assertCount(3, $checkpointStore->saved);
        $this->assertSame('drain-completed', $status->meta['checkpoint_reason']);
        $this->assertSame('2026-04-18T12:01:01.000+00:00', $status->meta['checkpoint_periodic_last_saved_at']);
        $this->assertSame('drain-requested', $checkpointStore->saved[1]->metadata['checkpoint_reason']);
        $this->assertSame('drain-completed', $checkpointStore->saved[2]->metadata['checkpoint_reason']);
    }

    private function makeRuntime(
        ?RuntimeRunnerInterface $runtimeRunner = null,
        ?WorkerReplayCheckpointManager $checkpointManager = null,
        int $drainTimeoutMilliseconds = 30000,
        int $checkpointIntervalSeconds = 60,
        ?TestClock $clock = null,
    ): WorkerRuntime {
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

        $resolver = new class implements ConnectionResolverInterface
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

        if ($clock !== null) {
            return new class($node, $resolver, $connectionFactory, $runtimeRunner ?? new NonLiveRuntimeRunner, $checkpointManager, $drainTimeoutMilliseconds, $checkpointIntervalSeconds, $clock) extends WorkerRuntime
            {
                public function __construct(
                    PbxNode $node,
                    ConnectionResolverInterface $connectionResolver,
                    ConnectionFactoryInterface $connectionFactory,
                    RuntimeRunnerInterface $runtimeRunner,
                    ?WorkerReplayCheckpointManager $checkpointManager,
                    int $drainTimeoutMilliseconds,
                    int $checkpointIntervalSeconds,
                    private readonly TestClock $clock,
                ) {
                    parent::__construct(
                        workerName: 'test-worker',
                        node: $node,
                        connectionResolver: $connectionResolver,
                        connectionFactory: $connectionFactory,
                        runtimeRunner: $runtimeRunner,
                        logger: new NullLogger,
                        checkpointManager: $checkpointManager,
                        drainTimeoutMilliseconds: $drainTimeoutMilliseconds,
                        checkpointIntervalSeconds: $checkpointIntervalSeconds,
                    );
                }

                protected function now(): \DateTimeImmutable
                {
                    return $this->clock->now;
                }
            };
        }

        return new WorkerRuntime(
            workerName: 'test-worker',
            node: $node,
            connectionResolver: $resolver,
            connectionFactory: $connectionFactory,
            runtimeRunner: $runtimeRunner ?? new NonLiveRuntimeRunner,
            logger: new NullLogger,
            checkpointManager: $checkpointManager,
            drainTimeoutMilliseconds: $drainTimeoutMilliseconds,
            checkpointIntervalSeconds: $checkpointIntervalSeconds,
        );
    }

    private function emptyArtifactStore(): ReplayArtifactStoreInterface
    {
        return new class implements ReplayArtifactStoreInterface
        {
            public function write(CapturedArtifactEnvelope $artifact): ReplayRecordId
            {
                throw new \BadMethodCallException('write() should not be called in this unit test.');
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
        };
    }
}

final class TestClock
{
    public \DateTimeImmutable $now;

    public function __construct(string $now)
    {
        $this->now = new \DateTimeImmutable($now);
    }

    public function advance(string $modify): void
    {
        $this->now = $this->now->modify($modify);
    }
}
