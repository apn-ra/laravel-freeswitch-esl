<?php

namespace ApnTalk\LaravelFreeswitchEsl\Tests\Integration\Http;

use ApnTalk\LaravelFreeswitchEsl\Contracts\HealthReporterInterface;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\HealthSnapshot;
use ApnTalk\LaravelFreeswitchEsl\Tests\TestCase;

class HealthRoutesTest extends TestCase
{
    public function test_http_health_summary_route_returns_db_backed_summary_payload(): void
    {
        $this->app->instance(HealthReporterInterface::class, new class implements HealthReporterInterface
        {
            public function forNode(int $pbxNodeId): HealthSnapshot
            {
                throw new \BadMethodCallException('unused');
            }

            public function forAllActive(): array
            {
                return [
                    new HealthSnapshot(
                        pbxNodeId: 1,
                        pbxNodeSlug: 'primary-fs',
                        providerCode: 'freeswitch',
                        status: HealthSnapshot::STATUS_HEALTHY,
                        connectionState: 'authenticated',
                        subscriptionState: 'active',
                        workerAssignmentScope: 'node',
                        inflightCount: 1,
                        retryAttempt: 0,
                        isDraining: false,
                        lastHeartbeatAt: new \DateTimeImmutable('2026-04-18T10:00:00+00:00'),
                        meta: [
                            'live_runtime_linked' => true,
                            'runtime_status_phase' => 'active',
                        ],
                    ),
                ];
            }

            public function forCluster(string $cluster): array
            {
                return $this->forAllActive();
            }

            public function record(HealthSnapshot $snapshot): void {}
        });

        $this->getJson('/freeswitch-esl/health')
            ->assertOk()
            ->assertJson([
                'report_surface' => 'health_snapshot_summary',
                'snapshot_basis' => 'db_health_snapshot',
                'live_runtime_linked' => true,
                'liveness_posture' => HealthSnapshot::STATUS_HEALTHY,
                'readiness_posture' => HealthSnapshot::STATUS_HEALTHY,
                'summary' => [
                    'node_count' => 1,
                    'healthy_count' => 1,
                    'degraded_count' => 0,
                    'unhealthy_count' => 0,
                    'unknown_count' => 0,
                ],
            ]);
    }

    public function test_http_health_liveness_and_readiness_routes_return_bounded_posture_payloads(): void
    {
        $this->app->instance(HealthReporterInterface::class, new class implements HealthReporterInterface
        {
            public function forNode(int $pbxNodeId): HealthSnapshot
            {
                throw new \BadMethodCallException('unused');
            }

            public function forAllActive(): array
            {
                return [
                    new HealthSnapshot(
                        pbxNodeId: 1,
                        pbxNodeSlug: 'primary-fs',
                        providerCode: 'freeswitch',
                        status: HealthSnapshot::STATUS_DEGRADED,
                        connectionState: 'reconnecting',
                        subscriptionState: 'disconnected',
                        workerAssignmentScope: 'node',
                        inflightCount: 0,
                        retryAttempt: 2,
                        isDraining: false,
                        lastHeartbeatAt: new \DateTimeImmutable('2026-04-18T10:00:00+00:00'),
                        meta: [
                            'live_runtime_linked' => true,
                            'runtime_status_phase' => 'reconnecting',
                        ],
                    ),
                ];
            }

            public function forCluster(string $cluster): array
            {
                return $this->forAllActive();
            }

            public function record(HealthSnapshot $snapshot): void {}
        });

        $this->getJson('/freeswitch-esl/health/live')
            ->assertOk()
            ->assertJson([
                'report_surface' => 'health_liveness_posture',
                'snapshot_basis' => 'db_health_snapshot',
                'live_runtime_linked' => true,
                'liveness_posture' => HealthSnapshot::STATUS_DEGRADED,
            ]);

        $this->getJson('/freeswitch-esl/health/ready')
            ->assertOk()
            ->assertJson([
                'report_surface' => 'health_readiness_posture',
                'snapshot_basis' => 'db_health_snapshot',
                'live_runtime_linked' => true,
                'readiness_posture' => HealthSnapshot::STATUS_DEGRADED,
            ]);
    }
}
