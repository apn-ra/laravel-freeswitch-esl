<?php

namespace ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects;

/**
 * Immutable value object representing a single PBX node endpoint.
 *
 * PbxNode carries the identity and connection parameters for one PBX server.
 * It is the primary unit of identity throughout the control plane, worker
 * orchestration, health reporting, and replay partitioning.
 *
 * password_secret_ref is an opaque reference — not the literal credential.
 * SecretResolverInterface resolves the actual credential at connection time.
 */
final class PbxNode
{
    /**
     * @param  string[]  $tags
     * @param  array<string, mixed>  $settings
     */
    public function __construct(
        public readonly int $id,
        public readonly int $providerId,
        public readonly string $providerCode,
        public readonly string $name,
        public readonly string $slug,
        public readonly string $host,
        public readonly int $port,
        public readonly string $username,
        public readonly string $passwordSecretRef,
        public readonly string $transport,
        public readonly bool $isActive,
        public readonly ?string $region = null,
        public readonly ?string $cluster = null,
        public readonly array $tags = [],
        public readonly array $settings = [],
        public readonly string $healthStatus = 'unknown',
        public readonly ?\DateTimeImmutable $lastHeartbeatAt = null,
    ) {}

    /**
     * Create from a database record array.
     *
     * @param  array<string, mixed>  $record
     */
    public static function fromRecord(array $record): self
    {
        $tags = [];

        if (isset($record['tags_json']) && $record['tags_json'] !== null) {
            $decoded = json_decode((string) $record['tags_json'], true);
            $tags = is_array($decoded) ? $decoded : [];
        }

        $settings = [];

        if (isset($record['settings_json']) && $record['settings_json'] !== null) {
            $decoded = json_decode((string) $record['settings_json'], true);
            $settings = is_array($decoded) ? $decoded : [];
        }

        $lastHeartbeat = null;

        if (! empty($record['last_heartbeat_at'])) {
            $lastHeartbeat = new \DateTimeImmutable((string) $record['last_heartbeat_at']);
        }

        return new self(
            id: (int) $record['id'],
            providerId: (int) $record['provider_id'],
            providerCode: (string) ($record['provider_code'] ?? ''),
            name: (string) $record['name'],
            slug: (string) $record['slug'],
            host: (string) $record['host'],
            port: (int) $record['port'],
            username: (string) ($record['username'] ?? ''),
            passwordSecretRef: (string) ($record['password_secret_ref'] ?? ''),
            transport: (string) ($record['transport'] ?? 'tcp'),
            isActive: (bool) $record['is_active'],
            region: isset($record['region']) ? (string) $record['region'] : null,
            cluster: isset($record['cluster']) ? (string) $record['cluster'] : null,
            tags: $tags,
            settings: $settings,
            healthStatus: (string) ($record['health_status'] ?? 'unknown'),
            lastHeartbeatAt: $lastHeartbeat,
        );
    }

    public function hasTag(string $tag): bool
    {
        return in_array($tag, $this->tags, true);
    }

    public function hasAnyTag(string ...$tags): bool
    {
        foreach ($tags as $tag) {
            if ($this->hasTag($tag)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return a stable runtime identity string for use in logs and metadata.
     */
    public function runtimeIdentity(): string
    {
        return sprintf('%s:%s#%d', $this->providerCode, $this->slug, $this->id);
    }
}
