<?php

namespace ApnTalk\LaravelFreeswitchEsl\Contracts;

use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\RuntimeRunnerFeedback;

/**
 * Optional feedback seam for runtime runners that expose coarse lifecycle state.
 *
 * This is observation only. Reconnect, heartbeat, and session lifecycle
 * ownership remain in the runtime package that produced the feedback.
 */
interface RuntimeRunnerFeedbackProviderInterface
{
    public function runtimeFeedback(): ?RuntimeRunnerFeedback;
}
