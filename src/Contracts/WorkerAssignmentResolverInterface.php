<?php

namespace ApnTalk\LaravelFreeswitchEsl\Contracts;

use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\PbxNode;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\WorkerAssignment;
use ApnTalk\LaravelFreeswitchEsl\Exceptions\PbxNotFoundException;

/**
 * Resolves which PBX nodes a given worker assignment scope should target.
 *
 * Assignment modes:
 *   - node      : targets a specific PBX node by ID or slug
 *   - cluster   : targets all active nodes in a named cluster
 *   - tag       : targets all active nodes matching a tag
 *   - provider  : targets all active nodes for a provider code
 *   - all-active: targets all currently active PBX nodes
 */
interface WorkerAssignmentResolverInterface
{
    /**
     * Resolve the set of PBX nodes for the given worker assignment.
     *
     * @return PbxNode[]
     *
     * @throws PbxNotFoundException
     */
    public function resolveNodes(WorkerAssignment $assignment): array;

    /**
     * Resolve target nodes for a named worker (looks up the DB assignment).
     *
     * @return PbxNode[]
     */
    public function resolveForWorkerName(string $workerName): array;
}
