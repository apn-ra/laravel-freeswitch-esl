<?php

namespace ApnTalk\LaravelFreeswitchEsl\ControlPlane\Models;

use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\ConnectionProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for the pbx_connection_profiles table.
 */
class PbxConnectionProfile extends Model
{
    protected $table = 'pbx_connection_profiles';

    protected $fillable = [
        'provider_id',
        'name',
        'retry_policy_json',
        'drain_policy_json',
        'subscription_profile_json',
        'replay_policy_json',
        'normalization_profile_json',
        'worker_profile_json',
        'settings_json',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'retry_policy_json' => 'array',
        'drain_policy_json' => 'array',
        'subscription_profile_json' => 'array',
        'replay_policy_json' => 'array',
        'normalization_profile_json' => 'array',
        'worker_profile_json' => 'array',
        'settings_json' => 'array',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(PbxProvider::class, 'provider_id');
    }

    /**
     * Convert to the VO used throughout the control plane.
     */
    public function toValueObject(): ConnectionProfile
    {
        $record = $this->toArray();

        // Re-encode back to JSON strings for the VO factory
        $jsonFields = [
            'retry_policy_json',
            'drain_policy_json',
            'subscription_profile_json',
            'replay_policy_json',
            'normalization_profile_json',
            'worker_profile_json',
            'settings_json',
        ];

        foreach ($jsonFields as $field) {
            if (is_array($record[$field] ?? null)) {
                $record[$field] = json_encode($record[$field]);
            }
        }

        return ConnectionProfile::fromRecord($record);
    }
}
