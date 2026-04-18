<?php

namespace ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects;

/**
 * Immutable value object representing a health snapshot for a single PBX node.
 *
 * Health state must be machine-usable. Consumers can act on this snapshot
 * without parsing free-text strings.
 */
final class HealthSnapshot
{
    public const META_RUNTIME_SNAPSHOT_KEY = 'health_runtime_snapshot';

    public const STATUS_HEALTHY = 'healthy';

    public const STATUS_DEGRADED = 'degraded';

    public const STATUS_UNHEALTHY = 'unhealthy';

    public const STATUS_UNKNOWN = 'unknown';

    /**
     * @param  list<array<string, string|null>>  $recentFailures
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly int $pbxNodeId,
        public readonly string $pbxNodeSlug,
        public readonly string $providerCode,
        public readonly string $status,
        public readonly string $connectionState,
        public readonly string $subscriptionState,
        public readonly string $workerAssignmentScope,
        public readonly int $inflightCount,
        public readonly int $retryAttempt,
        public readonly bool $isDraining,
        public readonly ?\DateTimeImmutable $lastHeartbeatAt,
        public readonly array $recentFailures = [],
        public readonly array $meta = [],
        public readonly ?\DateTimeImmutable $capturedAt = null,
    ) {}

    public function isHealthy(): bool
    {
        return $this->status === self::STATUS_HEALTHY;
    }

    public function isDegraded(): bool
    {
        return $this->status === self::STATUS_DEGRADED;
    }

    public function isUnhealthy(): bool
    {
        return $this->status === self::STATUS_UNHEALTHY;
    }

    public static function fromWorkerStatus(
        PbxNode $node,
        WorkerStatus $status,
        string $assignmentScope,
    ): self {
        $runtimeSource = self::metaString($status->meta, 'runtime_feedback_source');
        $liveRuntimeLinked = $runtimeSource === 'apntalk/esl-react-runtime-status-snapshot';
        $lastHeartbeatAt = self::statusDateTime(
            self::metaString($status->meta, 'runtime_last_heartbeat_at')
        ) ?? $status->lastHeartbeatAt;

        return new self(
            pbxNodeId: $node->id,
            pbxNodeSlug: $node->slug,
            providerCode: $node->providerCode,
            status: self::deriveRuntimeStatus($status, $liveRuntimeLinked),
            connectionState: self::metaString($status->meta, 'runtime_connection_state')
                ?? ($status->isHandoffPrepared() ? 'handoff-prepared' : 'unknown'),
            subscriptionState: self::metaString($status->meta, 'runtime_session_state') ?? 'unknown',
            workerAssignmentScope: $assignmentScope,
            inflightCount: $status->inflightCount,
            retryAttempt: $status->retryAttempt,
            isDraining: $status->isDraining(),
            lastHeartbeatAt: $lastHeartbeatAt,
            recentFailures: self::recentFailures($status),
            meta: [
                'snapshot_basis' => $liveRuntimeLinked
                    ? 'worker_runtime_status_snapshot'
                    : 'worker_runtime_status',
                'live_runtime_linked' => $liveRuntimeLinked,
                'runtime_truth_source' => $runtimeSource,
                'worker_name' => $status->workerName,
                'worker_session_id' => $status->sessionId,
                'runtime_status_phase' => self::metaString($status->meta, 'runtime_status_phase'),
                'runtime_active' => self::metaBool($status->meta, 'runtime_active'),
                'runtime_recovery_in_progress' => self::metaBool($status->meta, 'runtime_recovery_in_progress'),
                'runtime_connection_state' => self::metaString($status->meta, 'runtime_connection_state'),
                'runtime_session_state' => self::metaString($status->meta, 'runtime_session_state'),
                'runtime_authenticated' => self::metaBool($status->meta, 'runtime_authenticated'),
                'runtime_reconnect_attempts' => self::metaInt($status->meta, 'runtime_reconnect_attempts'),
                'runtime_last_successful_connect_at' => self::metaString($status->meta, 'runtime_last_successful_connect_at'),
                'runtime_last_disconnect_at' => self::metaString($status->meta, 'runtime_last_disconnect_at'),
                'runtime_last_disconnect_reason_class' => self::metaString($status->meta, 'runtime_last_disconnect_reason_class'),
                'runtime_last_disconnect_reason_message' => self::metaString($status->meta, 'runtime_last_disconnect_reason_message'),
                'runtime_last_failure_at' => self::metaString($status->meta, 'runtime_last_failure_at'),
                'runtime_last_failure_class' => self::metaString($status->meta, 'runtime_last_error_class'),
                'runtime_last_failure_message' => self::metaString($status->meta, 'runtime_last_error_message'),
                'runtime_draining' => self::metaBool($status->meta, 'runtime_draining') ?? $status->isDraining(),
            ],
            capturedAt: new \DateTimeImmutable,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'pbx_node_id' => $this->pbxNodeId,
            'pbx_node_slug' => $this->pbxNodeSlug,
            'provider_code' => $this->providerCode,
            'status' => $this->status,
            'connection_state' => $this->connectionState,
            'subscription_state' => $this->subscriptionState,
            'worker_assignment_scope' => $this->workerAssignmentScope,
            'inflight_count' => $this->inflightCount,
            'retry_attempt' => $this->retryAttempt,
            'is_draining' => $this->isDraining,
            'last_heartbeat_at' => $this->lastHeartbeatAt?->format(\DateTimeInterface::ATOM),
            'recent_failures' => $this->recentFailures,
            'meta' => $this->meta,
            'captured_at' => ($this->capturedAt ?? new \DateTimeImmutable)->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return list<array<string, string|null>>
     */
    private static function recentFailures(WorkerStatus $status): array
    {
        $failureAt = self::metaString($status->meta, 'runtime_last_failure_at');
        $failureClass = self::metaString($status->meta, 'runtime_last_error_class');
        $failureMessage = self::metaString($status->meta, 'runtime_last_error_message');

        if ($failureAt === null && $failureClass === null && $failureMessage === null) {
            return [];
        }

        return [[
            'at' => $failureAt,
            'class' => $failureClass,
            'message' => $failureMessage,
        ]];
    }

    private static function deriveRuntimeStatus(WorkerStatus $status, bool $liveRuntimeLinked): string
    {
        if (! $liveRuntimeLinked) {
            if ($status->isRuntimeLoopActive()) {
                return self::STATUS_HEALTHY;
            }

            if ($status->isRuntimeRunnerInvoked() || $status->isHandoffPrepared()) {
                return self::STATUS_DEGRADED;
            }

            return self::STATUS_UNKNOWN;
        }

        $phase = self::metaString($status->meta, 'runtime_status_phase');
        $runtimeActive = self::metaBool($status->meta, 'runtime_active');
        $runtimeAuthenticated = self::metaBool($status->meta, 'runtime_authenticated');
        $recoveryInProgress = self::metaBool($status->meta, 'runtime_recovery_in_progress');

        if ($phase === 'failed') {
            return self::STATUS_UNHEALTHY;
        }

        if ($recoveryInProgress === true) {
            return self::STATUS_DEGRADED;
        }

        if (in_array($phase, ['reconnecting', 'connecting', 'authenticating', 'draining', 'disconnected', 'closed'], true)) {
            return self::STATUS_DEGRADED;
        }

        if ($runtimeActive === true && $runtimeAuthenticated === true) {
            return self::STATUS_HEALTHY;
        }

        if ($runtimeActive === true) {
            return self::STATUS_DEGRADED;
        }

        return self::STATUS_UNKNOWN;
    }

    private static function statusDateTime(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return new \DateTimeImmutable($value);
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    private static function metaString(?array $meta, string $key): ?string
    {
        $value = $meta[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    private static function metaBool(?array $meta, string $key): ?bool
    {
        $value = $meta[$key] ?? null;

        return is_bool($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    private static function metaInt(?array $meta, string $key): ?int
    {
        $value = $meta[$key] ?? null;

        return is_int($value) ? $value : null;
    }
}
