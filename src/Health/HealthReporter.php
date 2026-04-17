<?php

namespace ApnTalk\LaravelFreeswitchEsl\Health;

use ApnTalk\LaravelFreeswitchEsl\Contracts\HealthReporterInterface;
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
        PbxNodeModel::query()->where('id', $snapshot->pbxNodeId)->update([
            'health_status' => $snapshot->status,
            'last_heartbeat_at' => $snapshot->lastHeartbeatAt,
        ]);
    }

    private function snapshotFromNode(
        PbxNode $node,
    ): HealthSnapshot {
        $status = $this->deriveStatus(
            storedStatus: $node->healthStatus,
            lastHeartbeat: $node->lastHeartbeatAt,
        );

        return new HealthSnapshot(
            pbxNodeId: $node->id,
            pbxNodeSlug: $node->slug,
            providerCode: $node->providerCode,
            status: $status,
            connectionState: $node->healthStatus,
            subscriptionState: 'unknown',
            workerAssignmentScope: 'unknown',
            inflightCount: 0,
            retryAttempt: 0,
            isDraining: false,
            lastHeartbeatAt: $node->lastHeartbeatAt,
            recentFailures: [],
            meta: [],
            capturedAt: new \DateTimeImmutable,
        );
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
