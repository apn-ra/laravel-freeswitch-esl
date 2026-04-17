<?php

namespace ApnTalk\LaravelFreeswitchEsl\Integration;

use Apntalk\EslReact\Contracts\RuntimeRunnerInterface as EslReactRuntimeRunnerInterface;
use Apntalk\EslReact\Runner\RuntimeRunnerState;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\RuntimeRunnerFeedback;
use Apntalk\EslReact\Runner\RuntimeRunnerHandle;
use ApnTalk\LaravelFreeswitchEsl\Contracts\RuntimeHandoffInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\RuntimeRunnerFeedbackProviderInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\RuntimeRunnerInterface;

/**
 * Laravel-owned adapter from RuntimeHandoffInterface to apntalk/esl-react.
 *
 * The live runtime loop, connector lifecycle, reconnect behavior, and heartbeat
 * state remain owned by apntalk/esl-react. This adapter only builds the
 * prepared bootstrap input and invokes the upstream runner seam.
 */
final class EslReactRuntimeRunnerAdapter implements RuntimeRunnerInterface, RuntimeRunnerFeedbackProviderInterface
{
    private ?RuntimeRunnerHandle $lastHandle = null;

    public function __construct(
        private readonly EslReactRuntimeRunnerInterface $runner,
        private readonly EslReactRuntimeBootstrapInputFactory $inputFactory,
    ) {}

    public function run(RuntimeHandoffInterface $handoff): void
    {
        $this->lastHandle = $this->runner->run($this->inputFactory->create($handoff));
    }

    public function lastHandle(): ?RuntimeRunnerHandle
    {
        return $this->lastHandle;
    }

    public function runtimeFeedback(): ?RuntimeRunnerFeedback
    {
        if ($this->lastHandle === null) {
            return null;
        }

        return new RuntimeRunnerFeedback(
            state: match ($this->lastHandle->state()) {
                RuntimeRunnerState::Starting => RuntimeRunnerFeedback::STATE_STARTING,
                RuntimeRunnerState::Running => RuntimeRunnerFeedback::STATE_RUNNING,
                RuntimeRunnerState::Failed => RuntimeRunnerFeedback::STATE_FAILED,
            },
            source: 'apntalk/esl-react-runtime-runner-handle',
            endpoint: $this->lastHandle->endpoint(),
            sessionId: $this->lastHandle->sessionContext()?->sessionId(),
            startupError: $this->lastHandle->startupError()?->getMessage(),
        );
    }
}
