<?php

namespace ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects;

/**
 * Immutable value object representing the operational status of a worker at a point in time.
 */
final class WorkerStatus
{
    public const STATE_BOOTING = 'booting';
    public const STATE_RUNNING = 'running';
    public const STATE_DRAINING = 'draining';
    public const STATE_RECONNECTING = 'reconnecting';
    public const STATE_SHUTDOWN = 'shutdown';
    public const STATE_FAILED = 'failed';

    /**
     * @param  string[]  $assignedNodeSlugs
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $sessionId,
        public readonly string $workerName,
        public readonly string $state,
        public readonly array $assignedNodeSlugs,
        public readonly int $inflightCount,
        public readonly int $retryAttempt,
        public readonly bool $isDraining,
        public readonly ?\DateTimeImmutable $lastHeartbeatAt,
        public readonly ?\DateTimeImmutable $bootedAt,
        public readonly array $meta = [],
    ) {}

    public function isRunning(): bool
    {
        return $this->state === self::STATE_RUNNING;
    }

    public function isDraining(): bool
    {
        return $this->isDraining || $this->state === self::STATE_DRAINING;
    }

    public function isShutdown(): bool
    {
        return $this->state === self::STATE_SHUTDOWN || $this->state === self::STATE_FAILED;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'session_id'          => $this->sessionId,
            'worker_name'         => $this->workerName,
            'state'               => $this->state,
            'assigned_node_slugs' => $this->assignedNodeSlugs,
            'inflight_count'      => $this->inflightCount,
            'retry_attempt'       => $this->retryAttempt,
            'is_draining'         => $this->isDraining,
            'last_heartbeat_at'   => $this->lastHeartbeatAt?->format(\DateTimeInterface::ATOM),
            'booted_at'           => $this->bootedAt?->format(\DateTimeInterface::ATOM),
            'meta'                => $this->meta,
        ];
    }
}
