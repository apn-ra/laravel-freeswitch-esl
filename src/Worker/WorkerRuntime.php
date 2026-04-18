<?php

namespace ApnTalk\LaravelFreeswitchEsl\Worker;

use ApnTalk\LaravelFreeswitchEsl\Contracts\ConnectionFactoryInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\ConnectionResolverInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\RuntimeHandoffInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\RuntimeRunnerFeedbackProviderInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\RuntimeRunnerInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\WorkerInterface;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\ConnectionContext;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\PbxNode;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\RuntimeRunnerFeedback;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\WorkerStatus;
use ApnTalk\LaravelFreeswitchEsl\Exceptions\WorkerException;
use ApnTalk\LaravelFreeswitchEsl\Integration\Replay\WorkerReplayCheckpointManager;
use Psr\Log\LoggerInterface;

/**
 * Single-node worker runtime managed by this Laravel package.
 *
 * Responsibilities owned here:
 *   - worker session identity
 *   - connection-context resolution per node
 *   - connection-handoff creation and persistence for downstream runtime consumers
 *   - bounded drain and checkpoint coordination
 *   - status reporting
 *
 * Responsibilities delegated to apntalk/esl-react:
 *   - actual TCP/TLS connection lifecycle
 *   - reconnect/backoff loop
 *   - subscription management
 *   - heartbeat monitoring
 *
 * Laravel checkpoint integration remains conservative: checkpoints record
 * progress over persisted replay artifacts only. They do not restore live ESL
 * sockets, upstream runtime sessions, or reconnect state.
 *
 * Boundary: do NOT add ESL frame parsing or subscription primitives here.
 */
class WorkerRuntime implements WorkerInterface
{
    private string $state = WorkerStatus::STATE_BOOTING;

    private bool $draining = false;

    private int $inflightCount = 0;

    private bool $runtimeRunnerInvoked = false;

    private ?\DateTimeImmutable $bootedAt = null;

    private ?\DateTimeImmutable $lastHeartbeatAt = null;

    private ?\DateTimeImmutable $drainStartedAt = null;

    private ?\DateTimeImmutable $drainDeadlineAt = null;

    private ?\DateTimeImmutable $drainCompletedAt = null;

    private ?\DateTimeImmutable $lastPeriodicCheckpointAt = null;

    private bool $drainTimedOut = false;

    private bool $drainTerminalCheckpointSaved = false;

    private readonly string $sessionId;

    /**
     * Resolved and session-tagged connection context, set during boot().
     * Null before boot() is called. Consumed by run() through the runner seam.
     */
    private ?ConnectionContext $resolvedContext = null;

    /**
     * Package-owned runtime handoff handle created during boot().
     * Null before boot() is called. Runtime adapters consume this.
     */
    private ?RuntimeHandoffInterface $runtimeHandoff = null;

    /**
     * @var array<string, mixed>
     */
    private array $checkpointMeta = [
        'checkpoint_enabled' => false,
        'checkpoint_key' => null,
        'checkpoint_is_resuming' => false,
        'checkpoint_last_consumed_sequence' => null,
        'checkpoint_saved_at' => null,
        'checkpoint_metadata' => null,
        'checkpoint_saved' => false,
        'checkpoint_reason' => null,
        'checkpoint_recovery_supported' => false,
        'checkpoint_recovery_reference_key' => null,
        'checkpoint_recovery_reference_saved_at' => null,
        'checkpoint_recovery_candidate_found' => false,
        'checkpoint_recovery_next_sequence' => null,
        'checkpoint_recovery_replay_session_id' => null,
        'checkpoint_recovery_job_uuid' => null,
        'checkpoint_recovery_pbx_node_slug' => null,
        'checkpoint_recovery_worker_session_id' => null,
    ];

    public function __construct(
        private readonly string $workerName,
        private readonly PbxNode $node,
        private readonly ConnectionResolverInterface $connectionResolver,
        private readonly ConnectionFactoryInterface $connectionFactory,
        private readonly RuntimeRunnerInterface $runtimeRunner,
        private readonly LoggerInterface $logger,
        private readonly ?WorkerReplayCheckpointManager $checkpointManager = null,
        private readonly int $drainTimeoutMilliseconds = 30000,
        private readonly int $checkpointIntervalSeconds = 60,
    ) {
        $this->sessionId = sprintf(
            '%s-%s-%s',
            $workerName,
            $node->slug,
            bin2hex(random_bytes(8))
        );
    }

    public function boot(): void
    {
        $this->logger->info('Worker booting', [
            'worker_name' => $this->workerName,
            'session_id' => $this->sessionId,
            'pbx_node_id' => $this->node->id,
            'pbx_node_slug' => $this->node->slug,
            'provider_code' => $this->node->providerCode,
        ]);

        $context = $this->connectionResolver->resolveForPbxNode($this->node);

        $this->resolvedContext = $context->withWorkerSession($this->sessionId);
        $this->runtimeHandoff = $this->connectionFactory->create($this->resolvedContext);
        $this->checkpointMeta = $this->checkpointManager?->resumeState($this->workerName, $this->resolvedContext)
            ?? $this->checkpointMeta;

        $this->logger->info('Connection context resolved', $this->resolvedContext->toLogContext());
        $this->logger->info('Connection handoff prepared', [
            'session_id' => $this->sessionId,
            'pbx_node_slug' => $this->node->slug,
            'endpoint' => $this->runtimeHandoff->endpoint(),
        ]);
        $this->logger->info('Worker replay checkpoint state resolved', [
            'session_id' => $this->sessionId,
            'pbx_node_slug' => $this->node->slug,
            'checkpoint_key' => $this->checkpointMeta['checkpoint_key'],
            'checkpoint_is_resuming' => $this->checkpointMeta['checkpoint_is_resuming'],
            'checkpoint_last_consumed_sequence' => $this->checkpointMeta['checkpoint_last_consumed_sequence'],
        ]);

        $this->bootedAt = $this->now();
        $this->state = WorkerStatus::STATE_RUNNING;
    }

    public function run(): void
    {
        if ($this->resolvedContext === null || $this->runtimeHandoff === null) {
            throw WorkerException::bootFailed(
                $this->workerName,
                'run() called before boot() — runtime handoff state is incomplete'
            );
        }

        $runtimeHandoff = $this->runtimeHandoff;

        $this->logger->info('Invoking runtime runner', [
            'session_id' => $this->sessionId,
            'pbx_node_slug' => $this->node->slug,
            'endpoint' => $runtimeHandoff->endpoint(),
            'runtime_runner' => $this->runtimeRunner::class,
        ]);

        $this->runtimeRunner->run($runtimeHandoff);
        $this->runtimeRunnerInvoked = true;
        $this->maybeSavePeriodicCheckpoint();

        $this->logger->info('Worker run completed after runtime runner invocation', [
            'session_id' => $this->sessionId,
            'pbx_node_slug' => $this->node->slug,
            'endpoint' => $runtimeHandoff->endpoint(),
            'runtime_runner' => $this->runtimeRunner::class,
        ]);
    }

    public function drain(): void
    {
        $this->refreshDrainState();

        $this->logger->info('Worker draining', [
            'session_id' => $this->sessionId,
            'pbx_node_slug' => $this->node->slug,
            'inflight' => $this->inflightCount,
        ]);

        $this->draining = true;
        $this->state = WorkerStatus::STATE_DRAINING;
        $this->drainStartedAt ??= $this->now();
        $this->drainDeadlineAt ??= $this->drainStartedAt->modify(sprintf('+%d milliseconds', $this->drainTimeoutMilliseconds));
        $this->drainTerminalCheckpointSaved = false;
        $this->checkpointMeta = array_merge(
            $this->checkpointMeta,
            $this->saveCheckpoint('drain-requested', [
                'drain_started_at' => $this->drainStartedAt->format(\DateTimeInterface::RFC3339_EXTENDED),
                'drain_deadline_at' => $this->drainDeadlineAt->format(\DateTimeInterface::RFC3339_EXTENDED),
                'inflight_count' => $this->inflightCount,
            ]),
        );

        $this->refreshDrainState();
    }

    public function shutdown(): void
    {
        $this->refreshDrainState();

        if ($this->draining && $this->resolvedContext !== null) {
            $this->persistTerminalDrainCheckpointIfNeeded();

            if (! $this->drainTerminalCheckpointSaved) {
                $this->checkpointMeta = array_merge(
                    $this->checkpointMeta,
                    $this->saveCheckpoint('shutdown', [
                        'drain_completed' => $this->drainCompletedAt !== null,
                        'drain_timed_out' => $this->drainTimedOut,
                        'inflight_count' => $this->inflightCount,
                    ]),
                );
            }
        }

        $this->logger->info('Worker shutting down', [
            'session_id' => $this->sessionId,
            'pbx_node_slug' => $this->node->slug,
        ]);

        $this->state = WorkerStatus::STATE_SHUTDOWN;
    }

    public function status(): WorkerStatus
    {
        $this->refreshDrainState();
        $this->maybeSavePeriodicCheckpoint();
        $runtimeFeedback = $this->runtimeFeedback();

        return new WorkerStatus(
            sessionId: $this->sessionId,
            workerName: $this->workerName,
            state: $this->effectiveState($runtimeFeedback),
            assignedNodeSlugs: [$this->node->slug],
            inflightCount: $this->inflightCount,
            retryAttempt: $runtimeFeedback?->reconnectAttempts ?? 0,
            isDraining: $this->draining,
            lastHeartbeatAt: $runtimeFeedback?->lastHeartbeatAt() ?? $this->lastHeartbeatAt,
            bootedAt: $this->bootedAt,
            meta: array_merge([
                'context_resolved' => $this->resolvedContext !== null,
                'connection_handoff_prepared' => $this->runtimeHandoff !== null,
                'runtime_adapter_ready' => $this->runtimeHandoff !== null,
                'handoff_endpoint' => $this->runtimeHandoff?->endpoint(),
                'runtime_handoff_contract' => $this->runtimeHandoff !== null ? RuntimeHandoffInterface::class : null,
                'runtime_handoff_class' => $this->runtimeHandoff !== null ? $this->runtimeHandoff::class : null,
                'runtime_runner_invoked' => $this->runtimeRunnerInvoked,
                'runtime_runner_contract' => RuntimeRunnerInterface::class,
                'runtime_runner_class' => $this->runtimeRunner::class,
                'runtime_loop_active' => false,
                'runtime_loop_active_source' => 'not-observed-by-laravel',
                'runtime_feedback_observed' => false,
                'runtime_feedback_source' => null,
                'runtime_feedback_delivery' => null,
                'runtime_push_lifecycle_observed' => false,
                'runtime_runner_state' => null,
                'runtime_status_phase' => null,
                'runtime_runner_endpoint' => null,
                'runtime_runner_session_id' => null,
                'runtime_startup_error_class' => null,
                'runtime_startup_error' => null,
                'runtime_connection_state' => null,
                'runtime_session_state' => null,
                'runtime_connected' => null,
                'runtime_authenticated' => null,
                'runtime_live' => null,
                'runtime_active' => null,
                'runtime_recovery_in_progress' => null,
                'runtime_reconnecting' => null,
                'runtime_draining' => null,
                'runtime_stopped' => null,
                'runtime_reconnect_attempts' => null,
                'runtime_last_heartbeat_at_micros' => null,
                'runtime_last_heartbeat_at' => null,
                'runtime_last_successful_connect_at_micros' => null,
                'runtime_last_successful_connect_at' => null,
                'runtime_last_disconnect_at_micros' => null,
                'runtime_last_disconnect_at' => null,
                'runtime_last_disconnect_reason_class' => null,
                'runtime_last_disconnect_reason_message' => null,
                'runtime_last_failure_at_micros' => null,
                'runtime_last_failure_at' => null,
                'runtime_last_error_class' => null,
                'runtime_last_error_message' => null,
                'drain_started_at' => $this->drainStartedAt?->format(\DateTimeInterface::RFC3339_EXTENDED),
                'drain_deadline_at' => $this->drainDeadlineAt?->format(\DateTimeInterface::RFC3339_EXTENDED),
                'drain_completed_at' => $this->drainCompletedAt?->format(\DateTimeInterface::RFC3339_EXTENDED),
                'drain_completed' => $this->drainCompletedAt !== null,
                'drain_timed_out' => $this->drainTimedOut,
                'drain_waiting_on_inflight' => $this->draining && $this->drainCompletedAt === null ? $this->inflightCount : 0,
                'checkpoint_periodic_enabled' => $this->checkpointIntervalSeconds > 0
                    && ($this->checkpointMeta['checkpoint_enabled'] ?? false) === true,
                'checkpoint_periodic_interval_seconds' => $this->checkpointIntervalSeconds > 0
                    ? $this->checkpointIntervalSeconds
                    : null,
                'checkpoint_periodic_last_saved_at' => $this->lastPeriodicCheckpointAt?->format(\DateTimeInterface::RFC3339_EXTENDED),
            ], $runtimeFeedback?->toMeta() ?? [], $this->checkpointMeta),
        );
    }

    private function runtimeFeedback(): ?RuntimeRunnerFeedback
    {
        if (! $this->runtimeRunner instanceof RuntimeRunnerFeedbackProviderInterface) {
            return null;
        }

        return $this->runtimeRunner->runtimeFeedback();
    }

    public function sessionId(): string
    {
        return $this->sessionId;
    }

    public function node(): PbxNode
    {
        return $this->node;
    }

    public function resolvedContext(): ?ConnectionContext
    {
        return $this->resolvedContext;
    }

    public function connectionHandle(): ?RuntimeHandoffInterface
    {
        return $this->runtimeHandoff;
    }

    public function runtimeHandoff(): ?RuntimeHandoffInterface
    {
        return $this->runtimeHandoff;
    }

    public function beginInflightWork(int $count = 1): void
    {
        if ($count < 1) {
            throw new \InvalidArgumentException('Inflight increment must be >= 1.');
        }

        $this->inflightCount += $count;
        $this->refreshDrainState();
        $this->maybeSavePeriodicCheckpoint();
    }

    public function completeInflightWork(int $count = 1): void
    {
        if ($count < 1) {
            throw new \InvalidArgumentException('Inflight decrement must be >= 1.');
        }

        $this->inflightCount = max(0, $this->inflightCount - $count);
        $this->refreshDrainState();
        $this->maybeSavePeriodicCheckpoint();
    }

    private function refreshDrainState(): void
    {
        if (! $this->draining || $this->drainCompletedAt !== null) {
            return;
        }

        $now = $this->now();

        if ($this->inflightCount === 0) {
            $this->drainCompletedAt = $now;
            $this->persistTerminalDrainCheckpointIfNeeded();

            return;
        }

        if ($this->drainDeadlineAt !== null && $now >= $this->drainDeadlineAt) {
            $this->drainTimedOut = true;
            $this->drainCompletedAt = $now;
            $this->persistTerminalDrainCheckpointIfNeeded();
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function saveCheckpoint(string $reason, array $metadata = []): array
    {
        if ($this->checkpointManager === null || $this->resolvedContext === null) {
            return [
                'checkpoint_saved' => false,
                'checkpoint_reason' => $reason,
            ];
        }

        return $this->checkpointManager->save($this->workerName, $this->resolvedContext, $reason, $metadata);
    }

    private function maybeSavePeriodicCheckpoint(): void
    {
        if (
            $this->checkpointIntervalSeconds < 1
            || $this->checkpointManager === null
            || $this->resolvedContext === null
            || $this->bootedAt === null
            || $this->draining
            || $this->state !== WorkerStatus::STATE_RUNNING
            || ! $this->runtimeRunnerInvoked
        ) {
            return;
        }

        $now = $this->now();
        $referenceTime = $this->lastPeriodicCheckpointAt ?? $this->bootedAt;
        $nextDueAt = $referenceTime->modify(sprintf('+%d seconds', $this->checkpointIntervalSeconds));

        if ($now < $nextDueAt) {
            return;
        }

        $result = $this->saveCheckpoint('periodic', [
            'periodic_checkpoint_interval_seconds' => $this->checkpointIntervalSeconds,
            'periodic_checkpoint_due_at' => $nextDueAt->format(\DateTimeInterface::RFC3339_EXTENDED),
            'inflight_count' => $this->inflightCount,
        ]);

        $this->checkpointMeta = array_merge($this->checkpointMeta, $result);

        if (($result['checkpoint_saved'] ?? false) === true) {
            $this->lastPeriodicCheckpointAt = $now;
        }
    }

    private function persistTerminalDrainCheckpointIfNeeded(): void
    {
        if (
            $this->drainTerminalCheckpointSaved
            || ! $this->draining
            || $this->drainCompletedAt === null
            || $this->resolvedContext === null
        ) {
            return;
        }

        $reason = $this->drainTimedOut ? 'drain-timeout' : 'drain-completed';

        $this->checkpointMeta = array_merge(
            $this->checkpointMeta,
            $this->saveCheckpoint($reason, [
                'drain_started_at' => $this->drainStartedAt?->format(\DateTimeInterface::RFC3339_EXTENDED),
                'drain_deadline_at' => $this->drainDeadlineAt?->format(\DateTimeInterface::RFC3339_EXTENDED),
                'drain_completed_at' => $this->drainCompletedAt->format(\DateTimeInterface::RFC3339_EXTENDED),
                'drain_completed' => true,
                'drain_timed_out' => $this->drainTimedOut,
                'inflight_count' => $this->inflightCount,
            ]),
        );

        $this->drainTerminalCheckpointSaved = true;
    }

    protected function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable;
    }

    private function effectiveState(?RuntimeRunnerFeedback $feedback): string
    {
        if ($this->state === WorkerStatus::STATE_SHUTDOWN) {
            return WorkerStatus::STATE_SHUTDOWN;
        }

        if ($this->draining) {
            return WorkerStatus::STATE_DRAINING;
        }

        return match ($feedback?->statusPhase) {
            'reconnecting' => WorkerStatus::STATE_RECONNECTING,
            'failed' => WorkerStatus::STATE_FAILED,
            default => $this->state,
        };
    }
}
