<?php

namespace ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects;

/**
 * Immutable value object carrying all resolved parameters needed to open a
 * connection to a PBX node.
 *
 * ConnectionContext is the output of the connection resolution pipeline:
 *   PbxRegistry → SecretResolver → ConnectionProfileResolver → ProviderDriver
 *   → ConnectionContext
 *
 * It carries full runtime identity (provider, node, profile) alongside the
 * resolved (plaintext) credentials and operational parameters.
 *
 * IMPORTANT: resolved_password contains a plaintext credential. Do not log or
 * serialize this object to persistent storage.
 */
final class ConnectionContext
{
    /**
     * @param  array<string, mixed>  $driverParameters  Provider-specific connection parameters
     */
    public function __construct(
        public readonly int $pbxNodeId,
        public readonly string $pbxNodeSlug,
        public readonly string $providerCode,
        public readonly string $host,
        public readonly int $port,
        public readonly string $username,
        /** @var string Plaintext resolved credential — do not log */
        public readonly string $resolvedPassword,
        public readonly string $transport,
        public readonly ?int $connectionProfileId,
        public readonly string $connectionProfileName,
        public readonly array $driverParameters = [],
        public readonly ?string $workerSessionId = null,
    ) {}

    /**
     * Return a safe loggable representation (no credential).
     *
     * @return array<string, mixed>
     */
    public function toLogContext(): array
    {
        return [
            'pbx_node_id'           => $this->pbxNodeId,
            'pbx_node_slug'         => $this->pbxNodeSlug,
            'provider_code'         => $this->providerCode,
            'host'                  => $this->host,
            'port'                  => $this->port,
            'username'              => $this->username,
            'transport'             => $this->transport,
            'connection_profile_id' => $this->connectionProfileId,
            'connection_profile'    => $this->connectionProfileName,
            'worker_session_id'     => $this->workerSessionId,
        ];
    }

    /**
     * Return a copy with the given worker session ID attached.
     */
    public function withWorkerSession(string $workerSessionId): self
    {
        return new self(
            pbxNodeId: $this->pbxNodeId,
            pbxNodeSlug: $this->pbxNodeSlug,
            providerCode: $this->providerCode,
            host: $this->host,
            port: $this->port,
            username: $this->username,
            resolvedPassword: $this->resolvedPassword,
            transport: $this->transport,
            connectionProfileId: $this->connectionProfileId,
            connectionProfileName: $this->connectionProfileName,
            driverParameters: $this->driverParameters,
            workerSessionId: $workerSessionId,
        );
    }
}
