<?php

namespace ApnTalk\LaravelFreeswitchEsl\Tests\Support\Fakes;

use ApnTalk\LaravelFreeswitchEsl\Contracts\RuntimeHandoffInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\RuntimeRunnerFeedbackProviderInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\RuntimeRunnerInterface;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\RuntimeRunnerFeedback;

final class MutableRuntimeRunner implements RuntimeRunnerFeedbackProviderInterface, RuntimeRunnerInterface
{
    public int $runCalls = 0;

    private ?RuntimeRunnerFeedback $feedback = null;

    public function __construct(
        private readonly ?\Closure $runCallback = null,
    ) {}

    public function run(RuntimeHandoffInterface $handoff): void
    {
        $this->runCalls++;

        if ($this->runCallback instanceof \Closure) {
            ($this->runCallback)($handoff, $this);
        }
    }

    public function runtimeFeedback(): ?RuntimeRunnerFeedback
    {
        return $this->feedback;
    }

    public function setFeedback(RuntimeRunnerFeedback $feedback): void
    {
        $this->feedback = $feedback;
    }
}
