<?php

namespace ApnTalk\LaravelFreeswitchEsl\Tests\Unit\ControlPlane\ValueObjects;

use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\HealthSnapshot;
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
            lastHeartbeatAt: new \DateTimeImmutable(),
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
}
