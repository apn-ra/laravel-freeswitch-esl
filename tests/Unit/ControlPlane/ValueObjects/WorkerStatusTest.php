<?php

namespace ApnTalk\LaravelFreeswitchEsl\Tests\Unit\ControlPlane\ValueObjects;

use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\WorkerStatus;
use PHPUnit\Framework\TestCase;

class WorkerStatusTest extends TestCase
{
    public function test_helper_methods_reflect_runtime_and_drain_posture_from_meta(): void
    {
        $status = new WorkerStatus(
            sessionId: 'worker-session-1',
            workerName: 'ingest-worker',
            state: WorkerStatus::STATE_RUNNING,
            assignedNodeSlugs: ['primary-fs'],
            inflightCount: 2,
            retryAttempt: 1,
            isDraining: false,
            lastHeartbeatAt: null,
            bootedAt: null,
            meta: [
                'connection_handoff_prepared' => true,
                'runtime_runner_invoked' => true,
                'runtime_feedback_observed' => true,
                'runtime_push_lifecycle_observed' => true,
                'runtime_loop_active' => true,
            ],
        );

        $this->assertTrue($status->isRunning());
        $this->assertTrue($status->isHandoffPrepared());
        $this->assertTrue($status->isRuntimeRunnerInvoked());
        $this->assertTrue($status->isRuntimeFeedbackObserved());
        $this->assertTrue($status->isRuntimePushObserved());
        $this->assertTrue($status->isRuntimeLoopActive());
        $this->assertFalse($status->isDraining());
        $this->assertFalse($status->isShutdown());
    }

    public function test_to_array_formats_timestamps_and_preserves_meta_shape(): void
    {
        $status = new WorkerStatus(
            sessionId: 'worker-session-2',
            workerName: 'ingest-worker',
            state: WorkerStatus::STATE_DRAINING,
            assignedNodeSlugs: ['primary-fs'],
            inflightCount: 0,
            retryAttempt: 0,
            isDraining: true,
            lastHeartbeatAt: new \DateTimeImmutable('2026-04-18T12:00:00+00:00'),
            bootedAt: new \DateTimeImmutable('2026-04-18T11:59:00+00:00'),
            meta: ['backpressure_reason' => 'draining'],
        );

        $array = $status->toArray();

        $this->assertSame('worker-session-2', $array['session_id']);
        $this->assertSame('draining', $array['state']);
        $this->assertSame('2026-04-18T12:00:00+00:00', $array['last_heartbeat_at']);
        $this->assertSame('2026-04-18T11:59:00+00:00', $array['booted_at']);
        $this->assertSame(['backpressure_reason' => 'draining'], $array['meta']);
        $this->assertTrue($status->isDraining());
    }
}
