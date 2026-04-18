<?php

namespace ApnTalk\LaravelFreeswitchEsl\Tests\Integration\Console;

use ApnTalk\LaravelFreeswitchEsl\Contracts\HealthReporterInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\PbxRegistryInterface;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\HealthSnapshot;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\PbxNode;
use ApnTalk\LaravelFreeswitchEsl\Tests\TestCase;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;

class FreeSwitchHealthCommandTest extends TestCase
{
    public function test_health_command_is_registered_with_the_console_kernel(): void
    {
        /** @var Kernel $kernel */
        $kernel = $this->app->make(Kernel::class);

        $this->assertArrayHasKey('freeswitch:health', $kernel->all());
    }

    public function test_health_command_renders_snapshots_for_active_nodes(): void
    {
        $snapshot = new HealthSnapshot(
            pbxNodeId: 1,
            pbxNodeSlug: 'primary-fs',
            providerCode: 'freeswitch',
            status: HealthSnapshot::STATUS_HEALTHY,
            connectionState: 'handoff-prepared',
            subscriptionState: 'not-started',
            workerAssignmentScope: 'node',
            inflightCount: 0,
            retryAttempt: 0,
            isDraining: false,
            lastHeartbeatAt: null,
        );

        $reporter = new class($snapshot) implements HealthReporterInterface
        {
            public function __construct(private readonly HealthSnapshot $snapshot) {}

            public function forNode(int $pbxNodeId): HealthSnapshot
            {
                return $this->snapshot;
            }

            public function forAllActive(): array
            {
                return [$this->snapshot];
            }

            public function forCluster(string $cluster): array
            {
                return [$this->snapshot];
            }

            public function record(HealthSnapshot $snapshot): void {}
        };

        $registry = new class implements PbxRegistryInterface
        {
            public function findById(int $id): PbxNode
            {
                return $this->node($id, 'primary-fs');
            }

            public function findBySlug(string $slug): PbxNode
            {
                return $this->node(1, $slug);
            }

            public function allActive(): array
            {
                return [];
            }

            public function allByCluster(string $cluster): array
            {
                return [];
            }

            public function allByTags(array $tags): array
            {
                return [];
            }

            public function allByProvider(string $providerCode): array
            {
                return [];
            }

            private function node(int $id, string $slug): PbxNode
            {
                return new PbxNode(
                    id: $id,
                    providerId: 1,
                    providerCode: 'freeswitch',
                    name: 'Primary FS',
                    slug: $slug,
                    host: '127.0.0.1',
                    port: 8021,
                    username: '',
                    passwordSecretRef: 'secret',
                    transport: 'tcp',
                    isActive: true,
                );
            }
        };

        $this->app->instance(HealthReporterInterface::class, $reporter);
        $this->app->instance(PbxRegistryInterface::class, $registry);

        $this->artisan('freeswitch:health')
            ->expectsTable(
                ['Node', 'Provider', 'Status', 'Connection', 'Last Heartbeat', 'Inflight', 'Draining'],
                [
                    ['primary-fs', 'freeswitch', 'healthy', 'handoff-prepared', 'never', '0', 'no'],
                ]
            )
            ->expectsOutputToContain('Observability posture: metrics driver log.')
            ->expectsOutputToContain('Runtime-linked health facts are not present in these stored health snapshots. Use a real worker run to record bounded upstream runtime-status facts when available.')
            ->expectsOutputToContain('Backpressure posture is not present in these stored health snapshots. Use worker runtime output for bounded live rejection posture.')
            ->expectsOutputToContain('Replay-backed recovery posture is not part of the default DB-backed health snapshot. Use worker runtime output for checkpoint/recovery visibility.')
            ->assertExitCode(0);
    }

    public function test_health_command_resolves_node_slug_via_registry_when_filtering_by_pbx(): void
    {
        $snapshot = new HealthSnapshot(
            pbxNodeId: 9,
            pbxNodeSlug: 'edge-fs',
            providerCode: 'freeswitch',
            status: HealthSnapshot::STATUS_DEGRADED,
            connectionState: 'db-health-only',
            subscriptionState: 'not-started',
            workerAssignmentScope: 'node',
            inflightCount: 2,
            retryAttempt: 0,
            isDraining: false,
            lastHeartbeatAt: null,
        );

        $reporter = new class($snapshot) implements HealthReporterInterface
        {
            public array $requestedNodeIds = [];

            public function __construct(private readonly HealthSnapshot $snapshot) {}

            public function forNode(int $pbxNodeId): HealthSnapshot
            {
                $this->requestedNodeIds[] = $pbxNodeId;

                return $this->snapshot;
            }

            public function forAllActive(): array
            {
                return [];
            }

            public function forCluster(string $cluster): array
            {
                return [];
            }

            public function record(HealthSnapshot $snapshot): void {}
        };

        $registry = new class implements PbxRegistryInterface
        {
            public int $lookupCalls = 0;

            public function findById(int $id): PbxNode
            {
                return $this->node($id, 'edge-fs');
            }

            public function findBySlug(string $slug): PbxNode
            {
                $this->lookupCalls++;

                return $this->node(9, $slug);
            }

            public function allActive(): array
            {
                return [];
            }

            public function allByCluster(string $cluster): array
            {
                return [];
            }

            public function allByTags(array $tags): array
            {
                return [];
            }

            public function allByProvider(string $providerCode): array
            {
                return [];
            }

            private function node(int $id, string $slug): PbxNode
            {
                return new PbxNode(
                    id: $id,
                    providerId: 1,
                    providerCode: 'freeswitch',
                    name: 'Edge FS',
                    slug: $slug,
                    host: '127.0.0.1',
                    port: 8021,
                    username: '',
                    passwordSecretRef: 'secret',
                    transport: 'tcp',
                    isActive: true,
                );
            }
        };

        $this->app->instance(HealthReporterInterface::class, $reporter);
        $this->app->instance(PbxRegistryInterface::class, $registry);

        $this->artisan('freeswitch:health', ['--pbx' => 'edge-fs'])
            ->expectsTable(
                ['Node', 'Provider', 'Status', 'Connection', 'Last Heartbeat', 'Inflight', 'Draining'],
                [
                    ['edge-fs', 'freeswitch', 'degraded', 'db-health-only', 'never', '2', 'no'],
                ]
            )
            ->expectsOutputToContain('Observability posture: metrics driver log.')
            ->expectsOutputToContain('Runtime-linked health facts are not present in these stored health snapshots. Use a real worker run to record bounded upstream runtime-status facts when available.')
            ->expectsOutputToContain('Backpressure posture is not present in these stored health snapshots. Use worker runtime output for bounded live rejection posture.')
            ->expectsOutputToContain('Replay-backed recovery posture is not part of the default DB-backed health snapshot. Use worker runtime output for checkpoint/recovery visibility.')
            ->assertExitCode(0);

        $this->assertSame(1, $registry->lookupCalls);
        $this->assertSame([9], $reporter->requestedNodeIds);
    }

    public function test_health_command_can_emit_machine_readable_aggregate_summary_when_requested(): void
    {
        $healthy = new HealthSnapshot(
            pbxNodeId: 1,
            pbxNodeSlug: 'primary-fs',
            providerCode: 'freeswitch',
            status: HealthSnapshot::STATUS_HEALTHY,
            connectionState: 'handoff-prepared',
            subscriptionState: 'unknown',
            workerAssignmentScope: 'unknown',
            inflightCount: 0,
            retryAttempt: 0,
            isDraining: false,
            lastHeartbeatAt: null,
        );
        $degraded = new HealthSnapshot(
            pbxNodeId: 2,
            pbxNodeSlug: 'edge-fs',
            providerCode: 'freeswitch',
            status: HealthSnapshot::STATUS_DEGRADED,
            connectionState: 'db-health-only',
            subscriptionState: 'unknown',
            workerAssignmentScope: 'unknown',
            inflightCount: 0,
            retryAttempt: 0,
            isDraining: false,
            lastHeartbeatAt: null,
        );

        $reporter = new class($healthy, $degraded) implements HealthReporterInterface
        {
            public function __construct(
                private readonly HealthSnapshot $healthy,
                private readonly HealthSnapshot $degraded,
            ) {}

            public function forNode(int $pbxNodeId): HealthSnapshot
            {
                return $this->healthy;
            }

            public function forAllActive(): array
            {
                return [$this->healthy, $this->degraded];
            }

            public function forCluster(string $cluster): array
            {
                return [$this->healthy, $this->degraded];
            }

            public function record(HealthSnapshot $snapshot): void {}
        };

        $this->app->instance(HealthReporterInterface::class, $reporter);

        /** @var Kernel $kernel */
        $kernel = $this->app->make(Kernel::class);

        $exitCode = $kernel->call('freeswitch:health', [
            '--summary' => true,
            '--json' => true,
        ]);

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode(trim($kernel->output()), true);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($decoded);
        $this->assertSame('health_snapshot_summary', $decoded['report_surface']);
        $this->assertSame('db_health_snapshot', $decoded['snapshot_basis']);
        $this->assertFalse($decoded['live_runtime_linked']);
        $this->assertSame([
            'node_count' => 2,
            'healthy_count' => 1,
            'degraded_count' => 1,
            'unhealthy_count' => 0,
            'unknown_count' => 0,
        ], $decoded['summary']);
        $this->assertSame(HealthSnapshot::STATUS_DEGRADED, $decoded['liveness_posture']);
        $this->assertSame(HealthSnapshot::STATUS_DEGRADED, $decoded['readiness_posture']);
        $this->assertCount(2, $decoded['snapshots']);
    }

    public function test_health_command_summary_marks_runtime_linked_when_snapshot_meta_reports_live_runtime_truth(): void
    {
        $snapshot = new HealthSnapshot(
            pbxNodeId: 1,
            pbxNodeSlug: 'primary-fs',
            providerCode: 'freeswitch',
            status: HealthSnapshot::STATUS_HEALTHY,
            connectionState: 'authenticated',
            subscriptionState: 'subscribed',
            workerAssignmentScope: 'node',
            inflightCount: 0,
            retryAttempt: 2,
            isDraining: false,
            lastHeartbeatAt: new \DateTimeImmutable('2026-04-18T10:00:00+00:00'),
            meta: [
                'snapshot_basis' => 'worker_runtime_status_snapshot',
                'live_runtime_linked' => true,
                'runtime_truth_source' => 'apntalk/esl-react-runtime-status-snapshot',
            ],
        );

        $reporter = new class($snapshot) implements HealthReporterInterface
        {
            public function __construct(private readonly HealthSnapshot $snapshot) {}

            public function forNode(int $pbxNodeId): HealthSnapshot
            {
                return $this->snapshot;
            }

            public function forAllActive(): array
            {
                return [$this->snapshot];
            }

            public function forCluster(string $cluster): array
            {
                return [$this->snapshot];
            }

            public function record(HealthSnapshot $snapshot): void {}
        };

        $this->app->instance(HealthReporterInterface::class, $reporter);

        /** @var Kernel $kernel */
        $kernel = $this->app->make(Kernel::class);

        $exitCode = $kernel->call('freeswitch:health', [
            '--summary' => true,
            '--json' => true,
        ]);

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode(trim($kernel->output()), true);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($decoded);
        $this->assertTrue($decoded['live_runtime_linked']);
    }

    public function test_health_command_can_render_human_summary_when_requested(): void
    {
        $snapshot = new HealthSnapshot(
            pbxNodeId: 1,
            pbxNodeSlug: 'primary-fs',
            providerCode: 'freeswitch',
            status: HealthSnapshot::STATUS_HEALTHY,
            connectionState: 'handoff-prepared',
            subscriptionState: 'unknown',
            workerAssignmentScope: 'unknown',
            inflightCount: 0,
            retryAttempt: 0,
            isDraining: false,
            lastHeartbeatAt: null,
        );

        $reporter = new class($snapshot) implements HealthReporterInterface
        {
            public function __construct(private readonly HealthSnapshot $snapshot) {}

            public function forNode(int $pbxNodeId): HealthSnapshot
            {
                return $this->snapshot;
            }

            public function forAllActive(): array
            {
                return [$this->snapshot];
            }

            public function forCluster(string $cluster): array
            {
                return [$this->snapshot];
            }

            public function record(HealthSnapshot $snapshot): void {}
        };

        $registry = new class implements PbxRegistryInterface
        {
            public function findById(int $id): PbxNode
            {
                return $this->node($id, 'primary-fs');
            }

            public function findBySlug(string $slug): PbxNode
            {
                return $this->node(1, $slug);
            }

            public function allActive(): array
            {
                return [];
            }

            public function allByCluster(string $cluster): array
            {
                return [];
            }

            public function allByTags(array $tags): array
            {
                return [];
            }

            public function allByProvider(string $providerCode): array
            {
                return [];
            }

            private function node(int $id, string $slug): PbxNode
            {
                return new PbxNode(
                    id: $id,
                    providerId: 1,
                    providerCode: 'freeswitch',
                    name: 'Primary FS',
                    slug: $slug,
                    host: '127.0.0.1',
                    port: 8021,
                    username: '',
                    passwordSecretRef: 'secret',
                    transport: 'tcp',
                    isActive: true,
                );
            }
        };

        $this->app->instance(HealthReporterInterface::class, $reporter);
        $this->app->instance(PbxRegistryInterface::class, $registry);

        $this->artisan('freeswitch:health', ['--summary' => true])
            ->expectsOutputToContain('Aggregate DB-backed health summary: 1 node(s); healthy 1; degraded 0; unhealthy 0; unknown 0; readiness healthy; liveness healthy.')
            ->assertExitCode(0);
    }

    public function test_health_command_renders_runtime_linked_snapshot_facts_when_present(): void
    {
        CarbonImmutable::setTestNow('2026-04-18T10:02:14+00:00');

        $snapshot = new HealthSnapshot(
            pbxNodeId: 1,
            pbxNodeSlug: 'primary-fs',
            providerCode: 'freeswitch',
            status: HealthSnapshot::STATUS_HEALTHY,
            connectionState: 'authenticated',
            subscriptionState: 'active',
            workerAssignmentScope: 'node',
            inflightCount: 1,
            retryAttempt: 2,
            isDraining: false,
            lastHeartbeatAt: new \DateTimeImmutable('2026-04-18T10:00:00+00:00'),
            meta: [
                'live_runtime_linked' => true,
                'runtime_status_phase' => 'active',
                'runtime_active' => true,
                'runtime_recovery_in_progress' => false,
                'runtime_last_successful_connect_at' => '2026-04-18T09:58:30.000+00:00',
                'runtime_last_disconnect_at' => '2026-04-18T09:57:00.000+00:00',
                'runtime_last_disconnect_reason_message' => 'disconnect observed',
                'runtime_last_failure_at' => '2026-04-18T09:57:10.000+00:00',
                'runtime_last_failure_message' => 'failure summary',
            ],
        );

        $reporter = new class($snapshot) implements HealthReporterInterface
        {
            public function __construct(private readonly HealthSnapshot $snapshot) {}

            public function forNode(int $pbxNodeId): HealthSnapshot
            {
                return $this->snapshot;
            }

            public function forAllActive(): array
            {
                return [$this->snapshot];
            }

            public function forCluster(string $cluster): array
            {
                return [$this->snapshot];
            }

            public function record(HealthSnapshot $snapshot): void {}
        };

        $registry = new class implements PbxRegistryInterface
        {
            public function findById(int $id): PbxNode
            {
                return $this->node($id, 'primary-fs');
            }

            public function findBySlug(string $slug): PbxNode
            {
                return $this->node(1, $slug);
            }

            public function allActive(): array
            {
                return [];
            }

            public function allByCluster(string $cluster): array
            {
                return [];
            }

            public function allByTags(array $tags): array
            {
                return [];
            }

            public function allByProvider(string $providerCode): array
            {
                return [];
            }

            private function node(int $id, string $slug): PbxNode
            {
                return new PbxNode(
                    id: $id,
                    providerId: 1,
                    providerCode: 'freeswitch',
                    name: 'Primary FS',
                    slug: $slug,
                    host: '127.0.0.1',
                    port: 8021,
                    username: '',
                    passwordSecretRef: 'secret',
                    transport: 'tcp',
                    isActive: true,
                );
            }
        };

        $this->app->instance(HealthReporterInterface::class, $reporter);
        $this->app->instance(PbxRegistryInterface::class, $registry);
        config()->set('freeswitch-esl.health.heartbeat_timeout_seconds', 300);

        try {
            $this->artisan('freeswitch:health')
                ->expectsTable(
                    ['Node', 'Provider', 'Status', 'Connection', 'Last Heartbeat', 'Inflight', 'Draining'],
                    [
                        ['primary-fs', 'freeswitch', 'healthy', 'authenticated', '2026-04-18 10:00:00', '1', 'no'],
                    ]
                )
                ->expectsOutputToContain('Observability posture: metrics driver log.')
                ->expectsOutputToContain('Runtime-linked snapshot facts:')
                ->expectsOutputToContain('Backpressure posture is not present in these stored health snapshots. Use worker runtime output for bounded live rejection posture.')
                ->expectsOutputToContain('primary-fs: runtime-linked yes; phase active; active yes; recovery_in_progress no; last_successful_connect 2026-04-18T09:58:30.000+00:00; last_disconnect 2026-04-18T09:57:00.000+00:00 (disconnect observed); last_failure 2026-04-18T09:57:10.000+00:00 (failure summary)')
                ->expectsOutputToContain('Runtime-linked snapshot age: 2m 14s')
                ->expectsOutputToContain('Replay-backed recovery posture is not part of the default DB-backed health snapshot. Use worker runtime output for checkpoint/recovery visibility.')
                ->assertExitCode(0);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_health_command_marks_runtime_linked_snapshot_age_as_potentially_stale_when_it_exceeds_timeout(): void
    {
        CarbonImmutable::setTestNow('2026-04-18T10:02:14+00:00');

        $snapshot = new HealthSnapshot(
            pbxNodeId: 1,
            pbxNodeSlug: 'primary-fs',
            providerCode: 'freeswitch',
            status: HealthSnapshot::STATUS_DEGRADED,
            connectionState: 'reconnecting',
            subscriptionState: 'disconnected',
            workerAssignmentScope: 'node',
            inflightCount: 0,
            retryAttempt: 4,
            isDraining: false,
            lastHeartbeatAt: new \DateTimeImmutable('2026-04-18T10:00:00+00:00'),
            meta: [
                'live_runtime_linked' => true,
                'runtime_status_phase' => 'reconnecting',
                'runtime_active' => true,
                'runtime_recovery_in_progress' => true,
            ],
        );

        $reporter = new class($snapshot) implements HealthReporterInterface
        {
            public function __construct(private readonly HealthSnapshot $snapshot) {}

            public function forNode(int $pbxNodeId): HealthSnapshot
            {
                return $this->snapshot;
            }

            public function forAllActive(): array
            {
                return [$this->snapshot];
            }

            public function forCluster(string $cluster): array
            {
                return [$this->snapshot];
            }

            public function record(HealthSnapshot $snapshot): void {}
        };

        $registry = new class implements PbxRegistryInterface
        {
            public function findById(int $id): PbxNode
            {
                return $this->node($id, 'primary-fs');
            }

            public function findBySlug(string $slug): PbxNode
            {
                return $this->node(1, $slug);
            }

            public function allActive(): array
            {
                return [];
            }

            public function allByCluster(string $cluster): array
            {
                return [];
            }

            public function allByTags(array $tags): array
            {
                return [];
            }

            public function allByProvider(string $providerCode): array
            {
                return [];
            }

            private function node(int $id, string $slug): PbxNode
            {
                return new PbxNode(
                    id: $id,
                    providerId: 1,
                    providerCode: 'freeswitch',
                    name: 'Primary FS',
                    slug: $slug,
                    host: '127.0.0.1',
                    port: 8021,
                    username: '',
                    passwordSecretRef: 'secret',
                    transport: 'tcp',
                    isActive: true,
                );
            }
        };

        $this->app->instance(HealthReporterInterface::class, $reporter);
        $this->app->instance(PbxRegistryInterface::class, $registry);
        config()->set('freeswitch-esl.health.heartbeat_timeout_seconds', 60);

        try {
            $this->artisan('freeswitch:health')
                ->expectsOutputToContain('Observability posture: metrics driver log.')
                ->expectsOutputToContain('Runtime-linked snapshot age: 2m 14s (may be stale)')
                ->assertExitCode(0);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_health_command_renders_human_readable_backpressure_action_when_snapshot_contains_it(): void
    {
        $snapshot = new HealthSnapshot(
            pbxNodeId: 1,
            pbxNodeSlug: 'primary-fs',
            providerCode: 'freeswitch',
            status: HealthSnapshot::STATUS_DEGRADED,
            connectionState: 'authenticated',
            subscriptionState: 'active',
            workerAssignmentScope: 'node',
            inflightCount: 2,
            retryAttempt: 0,
            isDraining: false,
            lastHeartbeatAt: null,
            meta: [
                'backpressure_active' => true,
                'backpressure_limit_reached' => true,
                'backpressure_reason' => 'max_inflight_exhausted',
                'backpressure_rejected_total' => 4,
                'max_inflight' => 2,
            ],
        );

        $reporter = new class($snapshot) implements HealthReporterInterface
        {
            public function __construct(private readonly HealthSnapshot $snapshot) {}

            public function forNode(int $pbxNodeId): HealthSnapshot
            {
                return $this->snapshot;
            }

            public function forAllActive(): array
            {
                return [$this->snapshot];
            }

            public function forCluster(string $cluster): array
            {
                return [$this->snapshot];
            }

            public function record(HealthSnapshot $snapshot): void {}
        };

        $registry = new class implements PbxRegistryInterface
        {
            public function findById(int $id): PbxNode
            {
                return $this->node($id, 'primary-fs');
            }

            public function findBySlug(string $slug): PbxNode
            {
                return $this->node(1, $slug);
            }

            public function allActive(): array
            {
                return [];
            }

            public function allByCluster(string $cluster): array
            {
                return [];
            }

            public function allByTags(array $tags): array
            {
                return [];
            }

            public function allByProvider(string $providerCode): array
            {
                return [];
            }

            private function node(int $id, string $slug): PbxNode
            {
                return new PbxNode(
                    id: $id,
                    providerId: 1,
                    providerCode: 'freeswitch',
                    name: 'Primary FS',
                    slug: $slug,
                    host: '127.0.0.1',
                    port: 8021,
                    username: '',
                    passwordSecretRef: 'secret',
                    transport: 'tcp',
                    isActive: true,
                );
            }
        };

        $this->app->instance(HealthReporterInterface::class, $reporter);
        $this->app->instance(PbxRegistryInterface::class, $registry);

        $this->artisan('freeswitch:health')
            ->expectsOutputToContain('Backpressure snapshot facts:')
            ->expectsOutputToContain('primary-fs: active yes; reason max_inflight_exhausted; max_inflight 2; rejected_total 4; operator action reduce inflight load or raise max_inflight deliberately')
            ->assertExitCode(0);
    }
}
