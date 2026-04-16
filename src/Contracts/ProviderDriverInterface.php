<?php

namespace ApnTalk\LaravelFreeswitchEsl\Contracts;

use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\ConnectionContext;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\ConnectionProfile;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\PbxNode;

/**
 * Implemented by each PBX provider driver (e.g. FreeSWITCH).
 *
 * A driver encapsulates provider-specific connection-factory behavior
 * without leaking protocol internals into the Laravel control plane.
 */
interface ProviderDriverInterface
{
    /**
     * The provider code this driver handles (e.g. 'freeswitch').
     */
    public function providerCode(): string;

    /**
     * Build a connection context for the given node and profile.
     *
     * The context carries all resolved runtime identity and parameters
     * needed to establish a connection via this driver.
     */
    public function buildConnectionContext(PbxNode $node, ConnectionProfile $profile): ConnectionContext;

    /**
     * Returns true if this driver supports the given capability.
     *
     * Capability codes are driver-specific (e.g. 'bgapi', 'outbound', 'tls').
     */
    public function supportsCapability(string $capability): bool;
}
