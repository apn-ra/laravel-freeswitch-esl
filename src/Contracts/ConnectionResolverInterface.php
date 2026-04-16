<?php

namespace ApnTalk\LaravelFreeswitchEsl\Contracts;

use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\ConnectionContext;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\PbxNode;

/**
 * Resolves a complete, ready-to-use ConnectionContext for a given PBX node.
 *
 * The resolver coordinates the PBX registry, connection profile resolver,
 * secret resolver, and provider driver to produce a fully enriched context.
 */
interface ConnectionResolverInterface
{
    /**
     * Resolve a ConnectionContext for the given PBX node ID.
     *
     * @throws \ApnTalk\LaravelFreeswitchEsl\Exceptions\ConnectionResolutionException
     * @throws \ApnTalk\LaravelFreeswitchEsl\Exceptions\PbxNotFoundException
     */
    public function resolveForNode(int $pbxNodeId): ConnectionContext;

    /**
     * Resolve a ConnectionContext for the given PBX node slug.
     *
     * @throws \ApnTalk\LaravelFreeswitchEsl\Exceptions\ConnectionResolutionException
     * @throws \ApnTalk\LaravelFreeswitchEsl\Exceptions\PbxNotFoundException
     */
    public function resolveForSlug(string $slug): ConnectionContext;

    /**
     * Resolve a ConnectionContext directly from a PbxNode value object.
     *
     * @throws \ApnTalk\LaravelFreeswitchEsl\Exceptions\ConnectionResolutionException
     */
    public function resolveForPbxNode(PbxNode $node): ConnectionContext;
}
