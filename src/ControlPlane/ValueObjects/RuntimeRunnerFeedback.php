<?php

namespace ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects;

/**
 * Coarse, Laravel-consumable runtime feedback from a bound runner.
 *
 * This snapshot is intentionally small. It records what Laravel can safely
 * observe without taking ownership of reconnect, heartbeat, or session
 * lifecycle behavior.
 */
final class RuntimeRunnerFeedback
{
    public const STATE_NOT_LIVE = 'not-live';
    public const STATE_STARTING = 'starting';
    public const STATE_RUNNING = 'running';
    public const STATE_FAILED = 'failed';

    public function __construct(
        public readonly string $state,
        public readonly string $source,
        public readonly ?string $endpoint = null,
        public readonly ?string $sessionId = null,
        public readonly ?string $startupError = null,
    ) {}

    public function isRuntimeLoopActive(): bool
    {
        return $this->state === self::STATE_RUNNING;
    }

    /**
     * @return array<string, bool|string|null>
     */
    public function toMeta(): array
    {
        return [
            'runtime_feedback_observed' => true,
            'runtime_feedback_source' => $this->source,
            'runtime_runner_state' => $this->state,
            'runtime_runner_endpoint' => $this->endpoint,
            'runtime_runner_session_id' => $this->sessionId,
            'runtime_startup_error' => $this->startupError,
            'runtime_loop_active' => $this->isRuntimeLoopActive(),
            'runtime_loop_active_source' => $this->source,
        ];
    }
}
