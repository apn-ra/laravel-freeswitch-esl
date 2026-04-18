<?php

namespace ApnTalk\LaravelFreeswitchEsl\Health;

use ApnTalk\LaravelFreeswitchEsl\Contracts\HealthReporterInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\MetricsRecorderInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\PbxRegistryInterface;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\Models\PbxNode as PbxNodeModel;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\HealthSnapshot;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\PbxNode;

/**
 * Produces structured health snapshots from the DB-backed control plane.
 *
 * In the initial implementation, health snapshots reflect the state stored
 * in the pbx_nodes table (health_status, last_heartbeat_at). As the worker
 * runtime matures, workers will push live state updates that get recorded
 * here via record().
 */
class HealthReporter implements HealthReporterInterface
{
    public function __construct(
        private readonly PbxRegistryInterface $pbxRegistry,
        private readonly MetricsRecorderInterface $metrics,
        private readonly int $heartbeatTimeoutSeconds,
    ) {}

    public function forNode(int $pbxNodeId): HealthSnapshot
    {
        $node = $this->pbxRegistry->findById($pbxNodeId);

        return $this->snapshotFromNode($node);
    }

    public function forAllActive(): array
    {
        return array_map(
            fn ($node) => $this->snapshotFromNode($node),
            $this->pbxRegistry->allActive()
        );
    }

    public function forCluster(string $cluster): array
    {
        return array_map(
            fn ($node) => $this->snapshotFromNode($node),
            $this->pbxRegistry->allByCluster($cluster)
        );
    }

    public function record(HealthSnapshot $snapshot): void
    {
        $model = PbxNodeModel::query()->findOrFail($snapshot->pbxNodeId);
        $storedSettings = $model->getAttribute('settings_json');
        $settings = is_array($storedSettings) ? $storedSettings : [];
        $runtimeSnapshot = $this->runtimeSnapshotPayload($snapshot);

        if ($runtimeSnapshot !== null) {
            $settings[HealthSnapshot::META_RUNTIME_SNAPSHOT_KEY] = $runtimeSnapshot;
        }

        $model->update([
            'health_status' => $snapshot->status,
            'last_heartbeat_at' => $snapshot->lastHeartbeatAt,
            'settings_json' => $settings,
        ]);

        $tags = [
            'provider_code' => $snapshot->providerCode,
            'pbx_node_slug' => $snapshot->pbxNodeSlug,
            'status' => $snapshot->status,
            'live_runtime_linked' => ($snapshot->meta['live_runtime_linked'] ?? false) === true,
        ];

        $this->metrics->increment('freeswitch_esl.health.snapshot_recorded', tags: $tags);
        $this->metrics->gauge('freeswitch_esl.health.inflight_count', $snapshot->inflightCount, $tags);
    }

    private function snapshotFromNode(
        PbxNode $node,
    ): HealthSnapshot {
        $runtimeSnapshot = $this->runtimeSnapshot($node);
        $status = $this->deriveStatus(
            storedStatus: $node->healthStatus,
            lastHeartbeat: $node->lastHeartbeatAt,
        );

        return new HealthSnapshot(
            pbxNodeId: $node->id,
            pbxNodeSlug: $node->slug,
            providerCode: $node->providerCode,
            status: $status,
            connectionState: $this->runtimeString($runtimeSnapshot, 'runtime_connection_state') ?? $node->healthStatus,
            subscriptionState: $this->runtimeString($runtimeSnapshot, 'runtime_session_state') ?? 'unknown',
            workerAssignmentScope: $this->runtimeString($runtimeSnapshot, 'worker_assignment_scope') ?? 'unknown',
            inflightCount: $this->runtimeInt($runtimeSnapshot, 'inflight_count') ?? 0,
            retryAttempt: $this->runtimeInt($runtimeSnapshot, 'runtime_reconnect_attempts') ?? 0,
            isDraining: $this->runtimeBool($runtimeSnapshot, 'runtime_draining') ?? false,
            lastHeartbeatAt: $node->lastHeartbeatAt,
            recentFailures: $this->runtimeRecentFailures($runtimeSnapshot),
            meta: [
                'snapshot_basis' => $this->runtimeString($runtimeSnapshot, 'snapshot_basis') ?? 'db_health_snapshot',
                'live_runtime_linked' => $this->runtimeBool($runtimeSnapshot, 'live_runtime_linked') ?? false,
                'runtime_truth_source' => $this->runtimeString($runtimeSnapshot, 'runtime_truth_source'),
                'runtime_status_phase' => $this->runtimeString($runtimeSnapshot, 'runtime_status_phase'),
                'runtime_active' => $this->runtimeBool($runtimeSnapshot, 'runtime_active'),
                'runtime_recovery_in_progress' => $this->runtimeBool($runtimeSnapshot, 'runtime_recovery_in_progress'),
                'runtime_last_successful_connect_at' => $this->runtimeString($runtimeSnapshot, 'runtime_last_successful_connect_at'),
                'runtime_last_disconnect_at' => $this->runtimeString($runtimeSnapshot, 'runtime_last_disconnect_at'),
                'runtime_last_disconnect_reason_class' => $this->runtimeString($runtimeSnapshot, 'runtime_last_disconnect_reason_class'),
                'runtime_last_disconnect_reason_message' => $this->runtimeString($runtimeSnapshot, 'runtime_last_disconnect_reason_message'),
                'runtime_last_failure_at' => $this->runtimeString($runtimeSnapshot, 'runtime_last_failure_at'),
                'runtime_last_failure_class' => $this->runtimeString($runtimeSnapshot, 'runtime_last_failure_class'),
                'runtime_last_failure_message' => $this->runtimeString($runtimeSnapshot, 'runtime_last_failure_message'),
            ],
            capturedAt: new \DateTimeImmutable,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function runtimeSnapshotPayload(HealthSnapshot $snapshot): ?array
    {
        $meta = $snapshot->meta;

        if (($meta['live_runtime_linked'] ?? false) !== true) {
            return null;
        }

        return [
            'snapshot_basis' => $meta['snapshot_basis'] ?? null,
            'live_runtime_linked' => true,
            'runtime_truth_source' => $meta['runtime_truth_source'] ?? null,
            'worker_name' => $meta['worker_name'] ?? null,
            'worker_session_id' => $meta['worker_session_id'] ?? null,
            'worker_assignment_scope' => $snapshot->workerAssignmentScope,
            'inflight_count' => $snapshot->inflightCount,
            'runtime_status_phase' => $meta['runtime_status_phase'] ?? null,
            'runtime_active' => $meta['runtime_active'] ?? null,
            'runtime_recovery_in_progress' => $meta['runtime_recovery_in_progress'] ?? null,
            'runtime_connection_state' => $meta['runtime_connection_state'] ?? null,
            'runtime_session_state' => $meta['runtime_session_state'] ?? null,
            'runtime_authenticated' => $meta['runtime_authenticated'] ?? null,
            'runtime_reconnect_attempts' => $meta['runtime_reconnect_attempts'] ?? null,
            'runtime_draining' => $meta['runtime_draining'] ?? null,
            'runtime_last_successful_connect_at' => $meta['runtime_last_successful_connect_at'] ?? null,
            'runtime_last_disconnect_at' => $meta['runtime_last_disconnect_at'] ?? null,
            'runtime_last_disconnect_reason_class' => $meta['runtime_last_disconnect_reason_class'] ?? null,
            'runtime_last_disconnect_reason_message' => $meta['runtime_last_disconnect_reason_message'] ?? null,
            'runtime_last_failure_at' => $meta['runtime_last_failure_at'] ?? null,
            'runtime_last_failure_class' => $meta['runtime_last_failure_class'] ?? null,
            'runtime_last_failure_message' => $meta['runtime_last_failure_message'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function runtimeSnapshot(PbxNode $node): ?array
    {
        $snapshot = $node->settings[HealthSnapshot::META_RUNTIME_SNAPSHOT_KEY] ?? null;

        return is_array($snapshot) ? $snapshot : null;
    }

    /**
     * @param  array<string, mixed>|null  $snapshot
     * @return list<array<string, string|null>>
     */
    private function runtimeRecentFailures(?array $snapshot): array
    {
        $at = $this->runtimeString($snapshot, 'runtime_last_failure_at');
        $class = $this->runtimeString($snapshot, 'runtime_last_failure_class');
        $message = $this->runtimeString($snapshot, 'runtime_last_failure_message');

        if ($at === null && $class === null && $message === null) {
            return [];
        }

        return [[
            'at' => $at,
            'class' => $class,
            'message' => $message,
        ]];
    }

    /**
     * @param  array<string, mixed>|null  $snapshot
     */
    private function runtimeString(?array $snapshot, string $key): ?string
    {
        $value = $snapshot[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>|null  $snapshot
     */
    private function runtimeBool(?array $snapshot, string $key): ?bool
    {
        $value = $snapshot[$key] ?? null;

        return is_bool($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>|null  $snapshot
     */
    private function runtimeInt(?array $snapshot, string $key): ?int
    {
        $value = $snapshot[$key] ?? null;

        return is_int($value) ? $value : null;
    }

    private function deriveStatus(string $storedStatus, ?\DateTimeImmutable $lastHeartbeat): string
    {
        if ($storedStatus === 'unhealthy') {
            return HealthSnapshot::STATUS_UNHEALTHY;
        }

        if ($lastHeartbeat !== null) {
            $age = time() - $lastHeartbeat->getTimestamp();

            if ($age > $this->heartbeatTimeoutSeconds) {
                return HealthSnapshot::STATUS_DEGRADED;
            }

            if ($storedStatus === 'healthy') {
                return HealthSnapshot::STATUS_HEALTHY;
            }
        }

        return HealthSnapshot::STATUS_UNKNOWN;
    }
}
