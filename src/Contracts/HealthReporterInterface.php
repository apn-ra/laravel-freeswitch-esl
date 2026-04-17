<?php

namespace ApnTalk\LaravelFreeswitchEsl\Contracts;

use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\HealthSnapshot;
use ApnTalk\LaravelFreeswitchEsl\Exceptions\PbxNotFoundException;

/**
 * Produces structured health snapshots for PBX nodes and aggregate scopes.
 *
 * Health state is machine-usable: consumers can act on it without parsing
 * free-text strings.
 */
interface HealthReporterInterface
{
    /**
     * Return a health snapshot for the given PBX node ID.
     *
     * @throws PbxNotFoundException
     */
    public function forNode(int $pbxNodeId): HealthSnapshot;

    /**
     * Return health snapshots for all active nodes.
     *
     * @return HealthSnapshot[]
     */
    public function forAllActive(): array;

    /**
     * Return health snapshots for all nodes in the given cluster.
     *
     * @return HealthSnapshot[]
     */
    public function forCluster(string $cluster): array;

    /**
     * Persist (or update) a health snapshot for a PBX node.
     */
    public function record(HealthSnapshot $snapshot): void;
}
