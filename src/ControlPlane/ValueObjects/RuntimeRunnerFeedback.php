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
        public readonly ?string $startupErrorClass = null,
        public readonly ?string $startupError = null,
        public readonly ?string $connectionState = null,
        public readonly ?string $sessionState = null,
        public readonly ?bool $isConnected = null,
        public readonly ?bool $isAuthenticated = null,
        public readonly ?bool $isLive = null,
        public readonly ?bool $isReconnecting = null,
        public readonly ?bool $isDraining = null,
        public readonly ?bool $isStopped = null,
        public readonly ?int $reconnectAttempts = null,
        public readonly ?float $lastHeartbeatAtMicros = null,
        public readonly ?string $lastRuntimeErrorClass = null,
        public readonly ?string $lastRuntimeErrorMessage = null,
    ) {}

    public static function fromEslReactLifecycleSnapshot(object $snapshot): self
    {
        return new self(
            state: self::stringValue($snapshot->runnerState ?? null) ?? self::STATE_STARTING,
            source: 'apntalk/esl-react-runtime-lifecycle-snapshot',
            endpoint: is_string($snapshot->endpoint ?? null) ? $snapshot->endpoint : null,
            sessionId: self::sessionId($snapshot),
            startupErrorClass: is_string($snapshot->startupErrorClass ?? null) ? $snapshot->startupErrorClass : null,
            startupError: is_string($snapshot->startupErrorMessage ?? null) ? $snapshot->startupErrorMessage : null,
            connectionState: self::optionalStringMethod($snapshot, 'connectionState'),
            sessionState: self::optionalStringMethod($snapshot, 'sessionState'),
            isConnected: self::optionalBoolMethod($snapshot, 'isConnected'),
            isAuthenticated: self::optionalBoolMethod($snapshot, 'isAuthenticated'),
            isLive: self::optionalBoolMethod($snapshot, 'isLive'),
            isReconnecting: self::optionalBoolMethod($snapshot, 'isReconnecting'),
            isDraining: self::optionalBoolMethod($snapshot, 'isDraining'),
            isStopped: self::optionalBoolMethod($snapshot, 'isStopped'),
            reconnectAttempts: self::optionalIntMethod($snapshot, 'reconnectAttempts'),
            lastHeartbeatAtMicros: self::optionalFloatMethod($snapshot, 'lastHeartbeatAtMicros'),
            lastRuntimeErrorClass: self::optionalStringMethod($snapshot, 'lastRuntimeErrorClass'),
            lastRuntimeErrorMessage: self::optionalStringMethod($snapshot, 'lastRuntimeErrorMessage'),
        );
    }

    public function isRuntimeLoopActive(): bool
    {
        return $this->isLive ?? $this->state === self::STATE_RUNNING;
    }

    /**
     * @return array<string, bool|float|int|string|null>
     */
    public function toMeta(): array
    {
        return [
            'runtime_feedback_observed' => true,
            'runtime_feedback_source' => $this->source,
            'runtime_runner_state' => $this->state,
            'runtime_runner_endpoint' => $this->endpoint,
            'runtime_runner_session_id' => $this->sessionId,
            'runtime_startup_error_class' => $this->startupErrorClass,
            'runtime_startup_error' => $this->startupError,
            'runtime_connection_state' => $this->connectionState,
            'runtime_session_state' => $this->sessionState,
            'runtime_connected' => $this->isConnected,
            'runtime_authenticated' => $this->isAuthenticated,
            'runtime_live' => $this->isLive,
            'runtime_reconnecting' => $this->isReconnecting,
            'runtime_draining' => $this->isDraining,
            'runtime_stopped' => $this->isStopped,
            'runtime_reconnect_attempts' => $this->reconnectAttempts,
            'runtime_last_heartbeat_at_micros' => $this->lastHeartbeatAtMicros,
            'runtime_last_error_class' => $this->lastRuntimeErrorClass,
            'runtime_last_error_message' => $this->lastRuntimeErrorMessage,
            'runtime_loop_active' => $this->isRuntimeLoopActive(),
            'runtime_loop_active_source' => $this->source,
        ];
    }

    private static function sessionId(object $snapshot): ?string
    {
        $context = $snapshot->sessionContext ?? null;

        if (is_object($context) && method_exists($context, 'sessionId')) {
            $sessionId = $context->sessionId();

            return is_string($sessionId) ? $sessionId : null;
        }

        return null;
    }

    private static function optionalBoolMethod(object $object, string $method): ?bool
    {
        if (! method_exists($object, $method)) {
            return null;
        }

        $value = $object->{$method}();

        return is_bool($value) ? $value : null;
    }

    private static function optionalIntMethod(object $object, string $method): ?int
    {
        if (! method_exists($object, $method)) {
            return null;
        }

        $value = $object->{$method}();

        return is_int($value) ? $value : null;
    }

    private static function optionalFloatMethod(object $object, string $method): ?float
    {
        if (! method_exists($object, $method)) {
            return null;
        }

        $value = $object->{$method}();

        return is_float($value) ? $value : null;
    }

    private static function optionalStringMethod(object $object, string $method): ?string
    {
        if (! method_exists($object, $method)) {
            return null;
        }

        return self::stringValue($object->{$method}());
    }

    private static function stringValue(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        if ($value instanceof \BackedEnum && is_string($value->value)) {
            return $value->value;
        }

        if ($value instanceof \UnitEnum) {
            return $value->name;
        }

        return null;
    }
}
