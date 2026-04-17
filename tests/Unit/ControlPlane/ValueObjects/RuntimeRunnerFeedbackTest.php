<?php

namespace ApnTalk\LaravelFreeswitchEsl\Tests\Unit\ControlPlane\ValueObjects;

use Apntalk\EslReact\Connection\ConnectionState;
use Apntalk\EslReact\Runner\RuntimeRunnerState;
use Apntalk\EslReact\Session\SessionState;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\RuntimeRunnerFeedback;
use PHPUnit\Framework\TestCase;

class RuntimeRunnerFeedbackTest extends TestCase
{
    public function test_from_esl_react_lifecycle_snapshot_maps_observed_runtime_truth(): void
    {
        $snapshot = new class {
            public string $endpoint = 'tcp://127.0.0.1:8021';

            public RuntimeRunnerState $runnerState = RuntimeRunnerState::Running;

            public ?string $startupErrorClass = null;

            public ?string $startupErrorMessage = null;

            public object $sessionContext;

            public function __construct()
            {
                $this->sessionContext = new class {
                    public function sessionId(): string
                    {
                        return 'worker-session-1';
                    }
                };
            }

            public function connectionState(): ConnectionState
            {
                return ConnectionState::Authenticated;
            }

            public function sessionState(): SessionState
            {
                return SessionState::Active;
            }

            public function isConnected(): bool
            {
                return true;
            }

            public function isAuthenticated(): bool
            {
                return true;
            }

            public function isLive(): bool
            {
                return true;
            }

            public function isReconnecting(): bool
            {
                return false;
            }

            public function isDraining(): bool
            {
                return false;
            }

            public function isStopped(): bool
            {
                return false;
            }

            public function reconnectAttempts(): int
            {
                return 0;
            }

            public function lastHeartbeatAtMicros(): float
            {
                return 123.45;
            }

            public function lastRuntimeErrorClass(): ?string
            {
                return null;
            }

            public function lastRuntimeErrorMessage(): ?string
            {
                return null;
            }
        };

        $feedback = RuntimeRunnerFeedback::fromEslReactLifecycleSnapshot($snapshot, 'push');
        $meta = $feedback->toMeta();

        $this->assertSame(RuntimeRunnerFeedback::STATE_RUNNING, $feedback->state);
        $this->assertSame('apntalk/esl-react-runtime-lifecycle-snapshot', $feedback->source);
        $this->assertSame('push', $feedback->delivery);
        $this->assertTrue($feedback->isRuntimeLoopActive());
        $this->assertSame('push', $meta['runtime_feedback_delivery']);
        $this->assertTrue($meta['runtime_push_lifecycle_observed']);
        $this->assertSame('tcp://127.0.0.1:8021', $meta['runtime_runner_endpoint']);
        $this->assertSame('worker-session-1', $meta['runtime_runner_session_id']);
        $this->assertSame('authenticated', $meta['runtime_connection_state']);
        $this->assertSame('active', $meta['runtime_session_state']);
        $this->assertTrue($meta['runtime_connected']);
        $this->assertTrue($meta['runtime_authenticated']);
        $this->assertTrue($meta['runtime_live']);
        $this->assertFalse($meta['runtime_reconnecting']);
        $this->assertFalse($meta['runtime_draining']);
        $this->assertFalse($meta['runtime_stopped']);
        $this->assertSame(0, $meta['runtime_reconnect_attempts']);
        $this->assertSame(123.45, $meta['runtime_last_heartbeat_at_micros']);
        $this->assertTrue($meta['runtime_loop_active']);
    }

    public function test_running_runner_state_does_not_mean_live_when_lifecycle_reports_not_live(): void
    {
        $snapshot = new class {
            public string $endpoint = 'tcp://127.0.0.1:8021';

            public RuntimeRunnerState $runnerState = RuntimeRunnerState::Running;

            public ?object $sessionContext = null;

            public ?string $startupErrorClass = null;

            public ?string $startupErrorMessage = null;

            public function connectionState(): ConnectionState
            {
                return ConnectionState::Reconnecting;
            }

            public function sessionState(): SessionState
            {
                return SessionState::Disconnected;
            }

            public function isLive(): bool
            {
                return false;
            }

            public function isReconnecting(): bool
            {
                return true;
            }
        };

        $feedback = RuntimeRunnerFeedback::fromEslReactLifecycleSnapshot($snapshot);
        $meta = $feedback->toMeta();

        $this->assertSame(RuntimeRunnerFeedback::STATE_RUNNING, $feedback->state);
        $this->assertSame('snapshot', $feedback->delivery);
        $this->assertFalse($feedback->isRuntimeLoopActive());
        $this->assertFalse($meta['runtime_loop_active']);
        $this->assertSame('snapshot', $meta['runtime_feedback_delivery']);
        $this->assertFalse($meta['runtime_push_lifecycle_observed']);
        $this->assertTrue($meta['runtime_reconnecting']);
        $this->assertSame('reconnecting', $meta['runtime_connection_state']);
        $this->assertSame('disconnected', $meta['runtime_session_state']);
    }

    public function test_failed_lifecycle_snapshot_maps_startup_and_runtime_errors(): void
    {
        $snapshot = new class {
            public string $endpoint = 'tcp://127.0.0.1:8021';

            public RuntimeRunnerState $runnerState = RuntimeRunnerState::Failed;

            public ?object $sessionContext = null;

            public string $startupErrorClass = \RuntimeException::class;

            public string $startupErrorMessage = 'startup failed';

            public function connectionState(): ConnectionState
            {
                return ConnectionState::Disconnected;
            }

            public function sessionState(): SessionState
            {
                return SessionState::Failed;
            }

            public function isLive(): bool
            {
                return false;
            }

            public function lastRuntimeErrorClass(): string
            {
                return \RuntimeException::class;
            }

            public function lastRuntimeErrorMessage(): string
            {
                return 'runtime failed';
            }
        };

        $meta = RuntimeRunnerFeedback::fromEslReactLifecycleSnapshot($snapshot)->toMeta();

        $this->assertSame(RuntimeRunnerFeedback::STATE_FAILED, $meta['runtime_runner_state']);
        $this->assertSame(\RuntimeException::class, $meta['runtime_startup_error_class']);
        $this->assertSame('startup failed', $meta['runtime_startup_error']);
        $this->assertSame(\RuntimeException::class, $meta['runtime_last_error_class']);
        $this->assertSame('runtime failed', $meta['runtime_last_error_message']);
        $this->assertFalse($meta['runtime_loop_active']);
    }
}
