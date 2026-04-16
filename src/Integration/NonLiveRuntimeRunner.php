<?php

namespace ApnTalk\LaravelFreeswitchEsl\Integration;

use ApnTalk\LaravelFreeswitchEsl\Contracts\RuntimeHandoffInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\RuntimeRunnerInterface;

/**
 * Truthful default runtime runner for the current non-live checkpoint.
 *
 * It marks the adapter seam as invokable without taking ownership of any live
 * async runtime loop, reconnect lifecycle, or heartbeat behavior.
 */
final class NonLiveRuntimeRunner implements RuntimeRunnerInterface
{
    public function run(RuntimeHandoffInterface $handoff): void
    {
        // Intentionally no-op. This package remains non-live until an
        // apntalk/esl-react-backed implementation is bound.
    }
}
