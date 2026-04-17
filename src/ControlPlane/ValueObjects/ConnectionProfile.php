<?php

namespace ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects;

/**
 * Immutable value object representing a reusable connection operational profile.
 *
 * A ConnectionProfile holds the operational policies (retry, drain, subscription,
 * replay, normalization, worker) that govern how a connection behaves at runtime.
 * It is resolved from the pbx_connection_profiles DB table or from config defaults.
 */
final class ConnectionProfile
{
    /**
     * @param  array<string, mixed>  $retryPolicy
     * @param  array<string, mixed>  $drainPolicy
     * @param  array<string, mixed>  $subscriptionProfile
     * @param  array<string, mixed>  $replayPolicy
     * @param  array<string, mixed>  $normalizationProfile
     * @param  array<string, mixed>  $workerProfile
     * @param  array<string, mixed>  $settings
     */
    public function __construct(
        public readonly ?int $id,
        public readonly ?int $providerId,
        public readonly string $name,
        public readonly array $retryPolicy = [],
        public readonly array $drainPolicy = [],
        public readonly array $subscriptionProfile = [],
        public readonly array $replayPolicy = [],
        public readonly array $normalizationProfile = [],
        public readonly array $workerProfile = [],
        public readonly array $settings = [],
    ) {}

    /**
     * Create from a database record array.
     *
     * @param  array<string, mixed>  $record
     */
    public static function fromRecord(array $record): self
    {
        return new self(
            id: isset($record['id']) ? (int) $record['id'] : null,
            providerId: isset($record['provider_id']) ? (int) $record['provider_id'] : null,
            name: (string) $record['name'],
            retryPolicy: self::decodeJson($record['retry_policy_json'] ?? null),
            drainPolicy: self::decodeJson($record['drain_policy_json'] ?? null),
            subscriptionProfile: self::decodeJson($record['subscription_profile_json'] ?? null),
            replayPolicy: self::decodeJson($record['replay_policy_json'] ?? null),
            normalizationProfile: self::decodeJson($record['normalization_profile_json'] ?? null),
            workerProfile: self::decodeJson($record['worker_profile_json'] ?? null),
            settings: self::decodeJson($record['settings_json'] ?? null),
        );
    }

    /**
     * Create a minimal default profile from config-level defaults.
     *
     * @param  array<string, mixed>  $retryDefaults
     * @param  array<string, mixed>  $drainDefaults
     */
    public static function fromConfigDefaults(
        array $retryDefaults = [],
        array $drainDefaults = [],
    ): self {
        return new self(
            id: null,
            providerId: null,
            name: 'default',
            retryPolicy: $retryDefaults,
            drainPolicy: $drainDefaults,
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function withRetryPolicy(array $overrides): self
    {
        return new self(
            id: $this->id,
            providerId: $this->providerId,
            name: $this->name,
            retryPolicy: array_merge($this->retryPolicy, $overrides),
            drainPolicy: $this->drainPolicy,
            subscriptionProfile: $this->subscriptionProfile,
            replayPolicy: $this->replayPolicy,
            normalizationProfile: $this->normalizationProfile,
            workerProfile: $this->workerProfile,
            settings: $this->settings,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeJson(mixed $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        $decoded = json_decode((string) $json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
