<?php

namespace ApnTalk\LaravelFreeswitchEsl\Contracts;

use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\PbxNode;

/**
 * Resolves live PBX node inventory from the database-backed control plane.
 *
 * The registry is the authoritative source for PBX node identity at runtime.
 * Configuration provides driver wiring and defaults; the database provides
 * the actual PBX inventory.
 */
interface PbxRegistryInterface
{
    /**
     * Resolve a single PBX node by its database ID.
     *
     * @throws \ApnTalk\LaravelFreeswitchEsl\Exceptions\PbxNotFoundException
     */
    public function findById(int $id): PbxNode;

    /**
     * Resolve a single PBX node by its slug.
     *
     * @throws \ApnTalk\LaravelFreeswitchEsl\Exceptions\PbxNotFoundException
     */
    public function findBySlug(string $slug): PbxNode;

    /**
     * Return all active PBX nodes.
     *
     * @return PbxNode[]
     */
    public function allActive(): array;

    /**
     * Return all active PBX nodes belonging to the given cluster.
     *
     * @return PbxNode[]
     */
    public function allByCluster(string $cluster): array;

    /**
     * Return all active PBX nodes matching at least one of the given tags.
     *
     * @param  string[]  $tags
     * @return PbxNode[]
     */
    public function allByTags(array $tags): array;

    /**
     * Return all active PBX nodes bound to the given provider code.
     *
     * @return PbxNode[]
     */
    public function allByProvider(string $providerCode): array;
}
