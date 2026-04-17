<?php

namespace ApnTalk\LaravelFreeswitchEsl\Contracts;

/**
 * Laravel-owned runtime runner seam.
 *
 * This contract is the adapter invocation point that WorkerRuntime::run() uses
 * once boot() has prepared a RuntimeHandoffInterface bundle. Implementations in
 * this package may remain non-live; the default production binding adapts the
 * prepared handoff into apntalk/esl-react and leaves live runtime ownership
 * with that package.
 */
interface RuntimeRunnerInterface
{
    public function run(RuntimeHandoffInterface $handoff): void;
}
