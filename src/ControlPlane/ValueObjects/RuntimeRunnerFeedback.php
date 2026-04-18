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
        public readonly string $delivery = 'snapshot',
        public readonly ?string $statusPhase = null,
        public readonly ?string $endpoint = null,
        public readonly ?string $sessionId = null,
        public readonly ?string $startupErrorClass = null,
        public readonly ?string $startupError = null,
        public readonly ?string $connectionState = null,
        public readonly ?string $sessionState = null,
        public readonly ?bool $isConnected = null,
        public readonly ?bool $isAuthenticated = null,
        public readonly ?bool $isLive = null,
        public readonly ?bool $isRuntimeActive = null,
        public readonly ?bool $isRecoveryInProgress = null,
        public readonly ?bool $isReconnecting = null,
        public readonly ?bool $isDraining = null,
        public readonly ?bool $isStopped = null,
        public readonly ?int $reconnectAttempts = null,
        public readonly ?float $lastHeartbeatAtMicros = null,
        public readonly ?float $lastSuccessfulConnectAtMicros = null,
        public readonly ?float $lastDisconnectAtMicros = null,
        public readonly ?string $lastDisconnectReasonClass = null,
        public readonly ?string $lastDisconnectReasonMessage = null,
        public readonly ?float $lastFailureAtMicros = null,
        public readonly ?string $lastRuntimeErrorClass = null,
        public readonly ?string $lastRuntimeErrorMessage = null,
    ) {}

    public static function fromEslReactStatusSnapshot(object $snapshot, string $delivery = 'snapshot'): self
    {
        $runnerState = self::stringValue($snapshot->runnerState ?? null) ?? self::STATE_STARTING;

        return new self(
            state: $runnerState,
            source: 'apntalk/esl-react-runtime-status-snapshot',
            delivery: $delivery,
            statusPhase: self::stringValue($snapshot->phase ?? null),
            endpoint: is_string($snapshot->endpoint ?? null) ? $snapshot->endpoint : null,
            sessionId: self::sessionId($snapshot),
            startupErrorClass: is_string($snapshot->startupErrorClass ?? null) ? $snapshot->startupErrorClass : null,
            startupError: is_string($snapshot->startupErrorMessage ?? null) ? $snapshot->startupErrorMessage : null,
            connectionState: self::healthEnumString($snapshot, 'connectionState'),
            sessionState: self::healthEnumString($snapshot, 'sessionState'),
            isConnected: self::healthBool($snapshot, 'isConnected'),
            isAuthenticated: self::healthBool($snapshot, 'isAuthenticated'),
            isLive: self::healthBoolProperty($snapshot, 'isLive'),
            isRuntimeActive: self::optionalBoolProperty($snapshot, 'isRuntimeActive'),
            isRecoveryInProgress: self::optionalBoolProperty($snapshot, 'isRecoveryInProgress'),
            isReconnecting: self::reconnectingFromStatusSnapshot($snapshot),
            isDraining: self::healthBoolProperty($snapshot, 'isDraining'),
            isStopped: self::statusPhaseIs($snapshot, 'closed') || self::statusPhaseIs($snapshot, 'failed'),
            reconnectAttempts: self::reconnectAttemptNumber($snapshot),
            lastHeartbeatAtMicros: self::healthFloatProperty($snapshot, 'lastHeartbeatAtMicros'),
            lastSuccessfulConnectAtMicros: self::optionalFloatProperty($snapshot, 'lastSuccessfulConnectAtMicros'),
            lastDisconnectAtMicros: self::optionalFloatProperty($snapshot, 'lastDisconnectAtMicros'),
            lastDisconnectReasonClass: self::optionalStringProperty($snapshot, 'lastDisconnectReasonClass'),
            lastDisconnectReasonMessage: self::optionalStringProperty($snapshot, 'lastDisconnectReasonMessage'),
            lastFailureAtMicros: self::optionalFloatProperty($snapshot, 'lastFailureAtMicros'),
            lastRuntimeErrorClass: self::optionalStringProperty($snapshot, 'lastFailureClass'),
            lastRuntimeErrorMessage: self::optionalStringProperty($snapshot, 'lastFailureMessage'),
        );
    }

    public static function fromEslReactLifecycleSnapshot(object $snapshot, string $delivery = 'snapshot'): self
    {
        return new self(
            state: self::stringValue($snapshot->runnerState ?? null) ?? self::STATE_STARTING,
            source: 'apntalk/esl-react-runtime-lifecycle-snapshot',
            delivery: $delivery,
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
        return $this->isRuntimeActive ?? $this->isLive ?? $this->state === self::STATE_RUNNING;
    }

    public function lastHeartbeatAt(): ?\DateTimeImmutable
    {
        return self::dateTimeFromMicros($this->lastHeartbeatAtMicros);
    }

    /**
     * @return array<string, bool|float|int|string|null>
     */
    public function toMeta(): array
    {
        return [
            'runtime_feedback_observed' => true,
            'runtime_feedback_source' => $this->source,
            'runtime_feedback_delivery' => $this->delivery,
            'runtime_push_lifecycle_observed' => $this->delivery === 'push',
            'runtime_runner_state' => $this->state,
            'runtime_status_phase' => $this->statusPhase,
            'runtime_runner_endpoint' => $this->endpoint,
            'runtime_runner_session_id' => $this->sessionId,
            'runtime_startup_error_class' => $this->startupErrorClass,
            'runtime_startup_error' => $this->startupError,
            'runtime_connection_state' => $this->connectionState,
            'runtime_session_state' => $this->sessionState,
            'runtime_connected' => $this->isConnected,
            'runtime_authenticated' => $this->isAuthenticated,
            'runtime_live' => $this->isLive,
            'runtime_active' => $this->isRuntimeActive,
            'runtime_recovery_in_progress' => $this->isRecoveryInProgress,
            'runtime_reconnecting' => $this->isReconnecting,
            'runtime_draining' => $this->isDraining,
            'runtime_stopped' => $this->isStopped,
            'runtime_reconnect_attempts' => $this->reconnectAttempts,
            'runtime_last_heartbeat_at_micros' => $this->lastHeartbeatAtMicros,
            'runtime_last_heartbeat_at' => self::dateTimeStringFromMicros($this->lastHeartbeatAtMicros),
            'runtime_last_successful_connect_at_micros' => $this->lastSuccessfulConnectAtMicros,
            'runtime_last_successful_connect_at' => self::dateTimeStringFromMicros($this->lastSuccessfulConnectAtMicros),
            'runtime_last_disconnect_at_micros' => $this->lastDisconnectAtMicros,
            'runtime_last_disconnect_at' => self::dateTimeStringFromMicros($this->lastDisconnectAtMicros),
            'runtime_last_disconnect_reason_class' => $this->lastDisconnectReasonClass,
            'runtime_last_disconnect_reason_message' => $this->lastDisconnectReasonMessage,
            'runtime_last_failure_at_micros' => $this->lastFailureAtMicros,
            'runtime_last_failure_at' => self::dateTimeStringFromMicros($this->lastFailureAtMicros),
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

    private static function optionalBoolProperty(object $object, string $property): ?bool
    {
        $value = $object->{$property} ?? null;

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

    private static function optionalIntProperty(object $object, string $property): ?int
    {
        $value = $object->{$property} ?? null;

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

    private static function optionalFloatProperty(object $object, string $property): ?float
    {
        $value = $object->{$property} ?? null;

        return is_float($value) ? $value : null;
    }

    private static function optionalStringMethod(object $object, string $method): ?string
    {
        if (! method_exists($object, $method)) {
            return null;
        }

        return self::stringValue($object->{$method}());
    }

    private static function optionalStringProperty(object $object, string $property): ?string
    {
        return self::stringValue($object->{$property} ?? null);
    }

    private static function healthEnumString(object $snapshot, string $property): ?string
    {
        $health = $snapshot->health ?? null;

        if (! is_object($health)) {
            return null;
        }

        return self::stringValue($health->{$property} ?? null);
    }

    private static function healthBool(object $snapshot, string $method): ?bool
    {
        $health = $snapshot->health ?? null;

        if (! is_object($health) || ! method_exists($health, $method)) {
            return null;
        }

        $value = $health->{$method}();

        return is_bool($value) ? $value : null;
    }

    private static function healthBoolProperty(object $snapshot, string $property): ?bool
    {
        $health = $snapshot->health ?? null;

        if (! is_object($health)) {
            return null;
        }

        $value = $health->{$property} ?? null;

        return is_bool($value) ? $value : null;
    }

    private static function healthFloatProperty(object $snapshot, string $property): ?float
    {
        $health = $snapshot->health ?? null;

        if (! is_object($health)) {
            return null;
        }

        $value = $health->{$property} ?? null;

        return is_float($value) ? $value : null;
    }

    private static function reconnectAttemptNumber(object $snapshot): ?int
    {
        $reconnectState = $snapshot->reconnectState ?? null;

        if (is_object($reconnectState)) {
            $attemptNumber = self::optionalIntProperty($reconnectState, 'attemptNumber');

            if ($attemptNumber !== null) {
                return $attemptNumber;
            }
        }

        return null;
    }

    private static function reconnectingFromStatusSnapshot(object $snapshot): ?bool
    {
        $phase = self::stringValue($snapshot->phase ?? null);

        if ($phase !== null) {
            return $phase === 'reconnecting';
        }

        return self::optionalBoolProperty($snapshot, 'isRecoveryInProgress');
    }

    private static function statusPhaseIs(object $snapshot, string $expected): bool
    {
        return self::stringValue($snapshot->phase ?? null) === $expected;
    }

    private static function dateTimeFromMicros(?float $micros): ?\DateTimeImmutable
    {
        if ($micros === null) {
            return null;
        }

        $seconds = sprintf('%.6f', $micros / 1000000);
        $dateTime = \DateTimeImmutable::createFromFormat('U.u', $seconds);

        return $dateTime ?: null;
    }

    private static function dateTimeStringFromMicros(?float $micros): ?string
    {
        return self::dateTimeFromMicros($micros)?->format(\DateTimeInterface::RFC3339_EXTENDED);
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
