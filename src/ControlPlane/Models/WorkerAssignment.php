<?php

namespace ApnTalk\LaravelFreeswitchEsl\ControlPlane\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for the worker_assignments table.
 */
class WorkerAssignment extends Model
{
    protected $table = 'worker_assignments';

    protected $fillable = [
        'worker_name',
        'assignment_mode',
        'pbx_node_id',
        'provider_code',
        'cluster',
        'tag',
        'is_active',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'pbx_node_id' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * @return BelongsTo<PbxNode, $this>
     */
    public function pbxNode(): BelongsTo
    {
        return $this->belongsTo(PbxNode::class, 'pbx_node_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForWorker(Builder $query, string $workerName): Builder
    {
        return $query->where('worker_name', $workerName);
    }

    /**
     * Convert to the VO used throughout the control plane.
     */
    public function toValueObject(): \ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\WorkerAssignment
    {
        return \ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\WorkerAssignment::fromRecord($this->toArray());
    }
}
