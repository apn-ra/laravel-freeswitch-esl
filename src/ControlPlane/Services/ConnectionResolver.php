<?php

namespace ApnTalk\LaravelFreeswitchEsl\ControlPlane\Services;

use ApnTalk\LaravelFreeswitchEsl\Contracts\ConnectionResolverInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\PbxRegistryInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\ProviderDriverRegistryInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\SecretResolverInterface;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\ConnectionContext;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\PbxNode;

/**
 * Coordinates the full connection resolution pipeline.
 *
 * Pipeline:
 *   PbxRegistry (node identity)
 *   → ConnectionProfileResolver (operational policy)
 *   → SecretResolver (credential)
 *   → ProviderDriverRegistry → ProviderDriver (driver-specific parameters)
 *   → ConnectionContext
 */
class ConnectionResolver implements ConnectionResolverInterface
{
    public function __construct(
        private readonly PbxRegistryInterface $pbxRegistry,
        private readonly ProviderDriverRegistryInterface $driverRegistry,
        private readonly SecretResolverInterface $secretResolver,
        private readonly ConnectionProfileResolver $profileResolver,
    ) {}

    public function resolveForNode(int $pbxNodeId): ConnectionContext
    {
        return $this->resolveForPbxNode($this->pbxRegistry->findById($pbxNodeId));
    }

    public function resolveForSlug(string $slug): ConnectionContext
    {
        return $this->resolveForPbxNode($this->pbxRegistry->findBySlug($slug));
    }

    public function resolveForPbxNode(PbxNode $node): ConnectionContext
    {
        $profile = $this->profileResolver->resolveDefaultForProvider($node->providerId);
        $password = $this->secretResolver->resolve($node->passwordSecretRef);
        $driver = $this->driverRegistry->resolve($node->providerCode);
        $context = $driver->buildConnectionContext($node, $profile);

        // Re-inject the resolved password — drivers should not call the secret resolver directly
        return new ConnectionContext(
            pbxNodeId: $context->pbxNodeId,
            pbxNodeSlug: $context->pbxNodeSlug,
            providerCode: $context->providerCode,
            host: $context->host,
            port: $context->port,
            username: $context->username,
            resolvedPassword: $password,
            transport: $context->transport,
            connectionProfileId: $context->connectionProfileId,
            connectionProfileName: $context->connectionProfileName,
            driverParameters: $context->driverParameters,
            workerSessionId: $context->workerSessionId,
        );
    }
}
