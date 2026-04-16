<?php

namespace ApnTalk\LaravelFreeswitchEsl\ControlPlane\Services;

use ApnTalk\LaravelFreeswitchEsl\Contracts\PbxRegistryInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\WorkerAssignmentResolverInterface;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\Models\WorkerAssignment as WorkerAssignmentModel;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\PbxNode;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\WorkerAssignment;
use ApnTalk\LaravelFreeswitchEsl\Exceptions\PbxNotFoundException;

/**
 * Resolves the set of PBX nodes for a given worker assignment scope.
 *
 * The resolver supports all five assignment modes and delegates node
 * lookups to the PbxRegistry so the DB remains the authoritative inventory.
 */
class WorkerAssignmentResolver implements WorkerAssignmentResolverInterface
{
    public function __construct(private readonly PbxRegistryInterface $pbxRegistry) {}

    /**
     * @return PbxNode[]
     */
    public function resolveNodes(WorkerAssignment $assignment): array
    {
        return match ($assignment->assignmentMode) {
            WorkerAssignment::MODE_NODE       => $this->resolveNodeMode($assignment),
            WorkerAssignment::MODE_CLUSTER    => $this->resolveClusterMode($assignment),
            WorkerAssignment::MODE_TAG        => $this->resolveTagMode($assignment),
            WorkerAssignment::MODE_PROVIDER   => $this->resolveProviderMode($assignment),
            WorkerAssignment::MODE_ALL_ACTIVE => $this->pbxRegistry->allActive(),
            default => [],
        };
    }

    /**
     * @return PbxNode[]
     */
    public function resolveForWorkerName(string $workerName): array
    {
        $assignments = WorkerAssignmentModel::query()
            ->where('is_active', true)
            ->where('worker_name', $workerName)
            ->get()
            ->map(fn (WorkerAssignmentModel $m) => $m->toValueObject())
            ->all();

        if (empty($assignments)) {
            return [];
        }

        $nodes = [];

        foreach ($assignments as $assignment) {
            foreach ($this->resolveNodes($assignment) as $node) {
                $nodes[$node->id] = $node;
            }
        }

        return array_values($nodes);
    }

    /** @return PbxNode[] */
    private function resolveNodeMode(WorkerAssignment $assignment): array
    {
        if ($assignment->pbxNodeId === null) {
            throw new PbxNotFoundException('WorkerAssignment in node mode has no pbx_node_id set.');
        }

        return [$this->pbxRegistry->findById($assignment->pbxNodeId)];
    }

    /** @return PbxNode[] */
    private function resolveClusterMode(WorkerAssignment $assignment): array
    {
        if ($assignment->cluster === null) {
            return [];
        }

        return $this->pbxRegistry->allByCluster($assignment->cluster);
    }

    /** @return PbxNode[] */
    private function resolveTagMode(WorkerAssignment $assignment): array
    {
        if ($assignment->tag === null) {
            return [];
        }

        return $this->pbxRegistry->allByTags([$assignment->tag]);
    }

    /** @return PbxNode[] */
    private function resolveProviderMode(WorkerAssignment $assignment): array
    {
        if ($assignment->providerCode === null) {
            return [];
        }

        return $this->pbxRegistry->allByProvider($assignment->providerCode);
    }
}
