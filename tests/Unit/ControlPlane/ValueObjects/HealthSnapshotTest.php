<?php

namespace ApnTalk\LaravelFreeswitchEsl\Tests\Unit\ControlPlane\ValueObjects;

use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\HealthSnapshot;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\PbxNode;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\WorkerStatus;
use PHPUnit\Framework\TestCase;

class HealthSnapshotTest extends TestCase
{
    private function makeSnapshot(string $status): HealthSnapshot
    {
        return new HealthSnapshot(
            pbxNodeId: 1,
            pbxNodeSlug: 'primary-fs',
            providerCode: 'freeswitch',
            status: $status,
            connectionState: 'connected',
            subscriptionState: 'subscribed',
            workerAssignmentScope: 'node',
            inflightCount: 5,
            retryAttempt: 0,
            isDraining: false,
            lastHeartbeatAt: new \DateTimeImmutable,
        );
    }

    public function test_is_healthy_returns_correct_result(): void
    {
        $this->assertTrue($this->makeSnapshot('healthy')->isHealthy());
        $this->assertFalse($this->makeSnapshot('degraded')->isHealthy());
        $this->assertFalse($this->makeSnapshot('unhealthy')->isHealthy());
    }

    public function test_is_degraded_returns_correct_result(): void
    {
        $this->assertTrue($this->makeSnapshot('degraded')->isDegraded());
        $this->assertFalse($this->makeSnapshot('healthy')->isDegraded());
    }

    public function test_is_unhealthy_returns_correct_result(): void
    {
        $this->assertTrue($this->makeSnapshot('unhealthy')->isUnhealthy());
        $this->assertFalse($this->makeSnapshot('healthy')->isUnhealthy());
    }

    public function test_to_array_contains_all_required_keys(): void
    {
        $snapshot = $this->makeSnapshot('healthy');
        $array = $snapshot->toArray();

        $required = [
            'pbx_node_id', 'pbx_node_slug', 'provider_code', 'status',
            'connection_state', 'subscription_state', 'worker_assignment_scope',
            'inflight_count', 'retry_attempt', 'is_draining',
            'last_heartbeat_at', 'recent_failures', 'meta', 'captured_at',
        ];

        foreach ($required as $key) {
            $this->assertArrayHasKey($key, $array, "Missing key: {$key}");
        }
    }

    public function test_to_array_formats_datetime_as_atom(): void
    {
        $snapshot = $this->makeSnapshot('healthy');
        $array = $snapshot->toArray();

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/',
            $array['captured_at']
        );
    }

    public function test_from_worker_status_marks_runtime_linked_failed_phase_as_unhealthy(): void
    {
        $snapshot = HealthSnapshot::fromWorkerStatus(
            new PbxNode(
                id: 1,
                providerId: 10,
                providerCode: 'freeswitch',
                name: 'Primary FS',
                slug: 'primary-fs',
                host: '127.0.0.1',
                port: 8021,
                username: '',
                passwordSecretRef: 'secret',
                transport: 'tcp',
                isActive: true,
            ),
            new WorkerStatus(
                sessionId: 'worker-session-1',
                workerName: 'health-worker',
                state: WorkerStatus::STATE_FAILED,
                assignedNodeSlugs: ['primary-fs'],
                inflightCount: 0,
                retryAttempt: 4,
                isDraining: false,
                lastHeartbeatAt: new \DateTimeImmutable('2026-04-18T10:00:00+00:00'),
                bootedAt: new \DateTimeImmutable('2026-04-18T09:58:00+00:00'),
                meta: [
                    'runtime_feedback_source' => 'apntalk/esl-react-runtime-status-snapshot',
                    'runtime_status_phase' => 'failed',
                    'runtime_active' => false,
                    'runtime_recovery_in_progress' => false,
                    'runtime_connection_state' => 'disconnected',
                    'runtime_session_state' => 'disconnected',
                    'runtime_last_failure_at' => '2026-04-18T09:59:50.000+00:00',
                    'runtime_last_error_class' => \LogicException::class,
                    'runtime_last_error_message' => 'upstream failure',
                ],
            ),
            'node',
        );

        $this->assertSame(HealthSnapshot::STATUS_UNHEALTHY, $snapshot->status);
        $this->assertTrue($snapshot->meta['live_runtime_linked']);
        $this->assertSame('failed', $snapshot->meta['runtime_status_phase']);
        $this->assertSame('upstream failure', $snapshot->meta['runtime_last_failure_message']);
        $this->assertCount(1, $snapshot->recentFailures);
    }
}
