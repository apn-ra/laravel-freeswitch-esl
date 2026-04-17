<?php

namespace ApnTalk\LaravelFreeswitchEsl\Integration;

use ApnTalk\LaravelFreeswitchEsl\Contracts\RuntimeHandoffInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\RuntimeRunnerFeedbackProviderInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\RuntimeRunnerInterface;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\RuntimeRunnerFeedback;

/**
 * Truthful fallback runtime runner for dry-run or unsupported environments.
 *
 * It marks the adapter seam as invokable without taking ownership of any live
 * async runtime loop, reconnect lifecycle, or heartbeat behavior.
 */
final class NonLiveRuntimeRunner implements RuntimeRunnerFeedbackProviderInterface, RuntimeRunnerInterface
{
    private ?RuntimeRunnerFeedback $feedback = null;

    public function run(RuntimeHandoffInterface $handoff): void
    {
        // Intentionally no-op. The production default is the esl-react adapter;
        // this fallback preserves a dry-run path without runtime ownership.
        $this->feedback = new RuntimeRunnerFeedback(
            state: RuntimeRunnerFeedback::STATE_NOT_LIVE,
            source: 'non-live-runtime-runner',
            endpoint: $handoff->endpoint(),
            sessionId: $handoff->context()->workerSessionId,
        );
    }

    public function runtimeFeedback(): ?RuntimeRunnerFeedback
    {
        return $this->feedback;
    }
}
