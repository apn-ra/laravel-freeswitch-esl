<?php

namespace ApnTalk\LaravelFreeswitchEsl\ControlPlane\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent model for the pbx_providers table.
 *
 * Represents a PBX provider family (e.g. FreeSWITCH). Not to be confused with
 * the PbxProvider value object — the model is the DB representation, the value
 * object is the immutable runtime identity.
 */
class PbxProvider extends Model
{
    protected $table = 'pbx_providers';

    protected $fillable = [
        'code',
        'name',
        'driver_class',
        'is_active',
        'capabilities_json',
        'settings_json',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'is_active'         => 'boolean',
        'capabilities_json' => 'array',
        'settings_json'     => 'array',
    ];

    public function nodes(): HasMany
    {
        return $this->hasMany(PbxNode::class, 'provider_id');
    }

    public function connectionProfiles(): HasMany
    {
        return $this->hasMany(PbxConnectionProfile::class, 'provider_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Convert to the VO used throughout the control plane.
     */
    public function toValueObject(): \ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\PbxProvider
    {
        return \ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\PbxProvider::fromRecord($this->toArray());
    }
}
