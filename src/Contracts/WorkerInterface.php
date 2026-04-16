<?php

namespace ApnTalk\LaravelFreeswitchEsl\Contracts;

use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\WorkerStatus;

/**
 * Implemented by the long-lived worker runtime that processes ESL events
 * for one or more PBX nodes.
 *
 * The worker lifecycle is:
 *   boot → run → (graceful drain) → shutdown
 */
interface WorkerInterface
{
    /**
     * Boot the worker for the given assignment scope.
     * Called once before run().
     */
    public function boot(): void;

    /**
     * Enter the main event loop. Blocks until shutdown is signaled.
     */
    public function run(): void;

    /**
     * Signal the worker to begin draining.
     * In drain mode the worker stops accepting new work but continues
     * processing inflight events until the drain timeout elapses.
     */
    public function drain(): void;

    /**
     * Signal the worker to shut down immediately.
     * Should clean up resources and return as quickly as safely possible.
     */
    public function shutdown(): void;

    /**
     * Return the current operational status of the worker.
     */
    public function status(): WorkerStatus;

    /**
     * A unique identifier for this worker session.
     * Carries runtime identity across logs, events, health snapshots, and replay.
     */
    public function sessionId(): string;
}
