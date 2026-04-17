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
    public const STATUS_HEALTHY = 'healthy';

    public const STATUS_DEGRADED = 'degraded';

    public const STATUS_UNHEALTHY = 'unhealthy';

    public const STATUS_UNKNOWN = 'unknown';

    /**
     * @param  array<string, mixed>  $recentFailures
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
}
