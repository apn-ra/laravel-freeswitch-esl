<?php

namespace ApnTalk\LaravelFreeswitchEsl\Integration;

use Apntalk\EslReact\Contracts\RuntimeRunnerInterface as EslReactRuntimeRunnerInterface;
use Apntalk\EslReact\Runner\RuntimeRunnerHandle;
use ApnTalk\LaravelFreeswitchEsl\Contracts\RuntimeHandoffInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\RuntimeRunnerFeedbackProviderInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\RuntimeRunnerInterface;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\RuntimeRunnerFeedback;

/**
 * Laravel-owned adapter from RuntimeHandoffInterface to apntalk/esl-react.
 *
 * The live runtime loop, connector lifecycle, reconnect behavior, and heartbeat
 * state remain owned by apntalk/esl-react. This adapter only builds the
 * prepared bootstrap input and invokes the upstream runner seam.
 */
final class EslReactRuntimeRunnerAdapter implements RuntimeRunnerFeedbackProviderInterface, RuntimeRunnerInterface
{
    private ?RuntimeRunnerHandle $lastHandle = null;

    private ?RuntimeRunnerFeedback $lastFeedback = null;

    public function __construct(
        private readonly EslReactRuntimeRunnerInterface $runner,
        private readonly EslReactRuntimeBootstrapInputFactory $inputFactory,
    ) {}

    public function run(RuntimeHandoffInterface $handoff): void
    {
        $this->lastHandle = $this->runner->run($this->inputFactory->create($handoff));
        $this->lastFeedback = null;

        $this->lastHandle->onLifecycleChange(function (object $snapshot): void {
            $this->lastFeedback = $this->feedbackFromHandle('push')
                ?? RuntimeRunnerFeedback::fromEslReactLifecycleSnapshot($snapshot, 'push');
        });
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

        return $this->lastFeedback
            ?? $this->feedbackFromHandle();
    }

    private function feedbackFromHandle(string $delivery = 'snapshot'): ?RuntimeRunnerFeedback
    {
        if ($this->lastHandle === null) {
            return null;
        }

        return RuntimeRunnerFeedback::fromEslReactStatusSnapshot(
            $this->lastHandle->statusSnapshot(),
            $delivery,
        );
    }
}
