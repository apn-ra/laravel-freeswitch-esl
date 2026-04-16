<?php

namespace ApnTalk\LaravelFreeswitchEsl\ControlPlane\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent model for the pbx_nodes table.
 *
 * Each PbxNode represents a single PBX endpoint. The model is the DB
 * representation; PbxNode value object is the immutable runtime identity.
 */
class PbxNode extends Model
{
    protected $table = 'pbx_nodes';

    protected $fillable = [
        'provider_id',
        'name',
        'slug',
        'host',
        'port',
        'username',
        'password_secret_ref',
        'transport',
        'is_active',
        'region',
        'cluster',
        'tags_json',
        'settings_json',
        'health_status',
        'last_heartbeat_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'port'              => 'integer',
        'is_active'         => 'boolean',
        'tags_json'         => 'array',
        'settings_json'     => 'array',
        'last_heartbeat_at' => 'datetime',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(PbxProvider::class, 'provider_id');
    }

    public function workerAssignments(): HasMany
    {
        return $this->hasMany(WorkerAssignment::class, 'pbx_node_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeInCluster(Builder $query, string $cluster): Builder
    {
        return $query->where('cluster', $cluster);
    }

    public function scopeWithTag(Builder $query, string $tag): Builder
    {
        /** @var Builder $scoped */
        $scoped = $query->whereJsonContains('tags_json', $tag);

        return $scoped;
    }

    public function scopeForProvider(Builder $query, string $providerCode): Builder
    {
        return $query->whereHas(
            'provider',
            fn (Builder $q) => $q->where('code', $providerCode)
        );
    }

    /**
     * Convert to the VO used throughout the control plane.
     */
    public function toValueObject(): \ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\PbxNode
    {
        $record = $this->toArray();

        // Re-encode JSON columns back to strings so fromRecord can decode them
        if (is_array($record['tags_json'] ?? null)) {
            $record['tags_json'] = json_encode($record['tags_json']);
        }

        if (is_array($record['settings_json'] ?? null)) {
            $record['settings_json'] = json_encode($record['settings_json']);
        }

        $record['provider_code'] = $this->provider?->code ?? '';

        return \ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\PbxNode::fromRecord($record);
    }
}
