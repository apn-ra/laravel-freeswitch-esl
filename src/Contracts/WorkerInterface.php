<?php

namespace ApnTalk\LaravelFreeswitchEsl\Contracts;

use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\WorkerStatus;

/**
 * Implemented by the worker/runtime scaffolding for one or more PBX nodes.
 *
 * In the current package posture, implementations own assignment-aware boot,
 * retained runtime handoff state, drain/shutdown signaling, and status
 * reporting. They do not yet guarantee a live async event loop.
 *
 * Lifecycle:
 *   boot → run → drain → shutdown
 */
interface WorkerInterface
{
    /**
     * Prepare the worker/runtime handoff state for the current assignment scope.
     *
     * Called once before run(). Current implementations resolve connection
     * context and retain any package-owned runtime handoff handle here.
     */
    public function boot(): void;

    /**
     * Consume the prepared runtime handoff state for the current implementation.
     *
     * In the current scaffolding posture this may log and return immediately.
     * Future apntalk/esl-react-backed implementations may block in a live
     * async runtime loop.
     */
    public function run(): void;

    /**
     * Signal the worker to enter drain mode.
     *
     * Current implementations perform bounded Laravel-owned drain coordination:
     * they record drain intent, expose drain deadline/completion state, and may
     * snapshot replay-backed checkpoints. They do not own upstream runtime-loop
     * reconnect or async shutdown orchestration.
     */
    public function drain(): void;

    /**
     * Signal the worker/runtime scaffolding to shut down.
     *
     * Current implementations update local state and release package-owned
     * scaffolding resources. Future runtime-backed implementations may perform
     * additional loop/session cleanup.
     */
    public function shutdown(): void;

    /**
     * Return the current operational status of the worker.
     */
    public function status(): WorkerStatus;

    /**
     * A unique identifier for this worker session.
     * Carries runtime identity across logs and retained handoff state, and is
     * intended to propagate into future event/replay integrations.
     */
    public function sessionId(): string;
}
