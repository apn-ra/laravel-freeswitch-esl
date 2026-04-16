<?php

namespace ApnTalk\LaravelFreeswitchEsl\ControlPlane\Services;

use ApnTalk\LaravelFreeswitchEsl\ControlPlane\Models\PbxConnectionProfile;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\ConnectionProfile;

/**
 * Resolves a ConnectionProfile for a given PBX node.
 *
 * Resolution order:
 *   1. Named profile in the request (if given)
 *   2. Default profile for the node's provider (if exists in DB)
 *   3. Global config defaults
 */
class ConnectionProfileResolver
{
    /**
     * @param  array<string, mixed>  $retryDefaults
     * @param  array<string, mixed>  $drainDefaults
     */
    public function __construct(
        private readonly array $retryDefaults,
        private readonly array $drainDefaults,
    ) {}

    /**
     * Resolve by profile name from the database.
     */
    public function resolveByName(string $profileName): ConnectionProfile
    {
        $model = PbxConnectionProfile::where('name', $profileName)->first();

        if ($model === null) {
            return ConnectionProfile::fromConfigDefaults($this->retryDefaults, $this->drainDefaults);
        }

        return $model->toValueObject();
    }

    /**
     * Resolve the default profile for a given provider ID, falling back to config defaults.
     */
    public function resolveDefaultForProvider(int $providerId): ConnectionProfile
    {
        $model = PbxConnectionProfile::where('provider_id', $providerId)->first();

        if ($model === null) {
            return ConnectionProfile::fromConfigDefaults($this->retryDefaults, $this->drainDefaults);
        }

        return $model->toValueObject();
    }

    /**
     * Return config-level defaults as a ConnectionProfile.
     */
    public function defaults(): ConnectionProfile
    {
        return ConnectionProfile::fromConfigDefaults($this->retryDefaults, $this->drainDefaults);
    }
}
