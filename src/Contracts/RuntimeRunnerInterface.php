<?php

namespace ApnTalk\LaravelFreeswitchEsl\Contracts;

/**
 * Laravel-owned runtime runner seam.
 *
 * This contract is the adapter invocation point that WorkerRuntime::run() uses
 * once boot() has prepared a RuntimeHandoffInterface bundle. Implementations in
 * this package may remain non-live; a future apntalk/esl-react-backed adapter
 * will implement the actual long-lived runtime behavior.
 */
interface RuntimeRunnerInterface
{
    public function run(RuntimeHandoffInterface $handoff): void;
}
