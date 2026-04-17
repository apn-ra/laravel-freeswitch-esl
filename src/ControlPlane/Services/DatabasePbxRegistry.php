<?php

namespace ApnTalk\LaravelFreeswitchEsl\ControlPlane\Services;

use ApnTalk\LaravelFreeswitchEsl\Contracts\PbxRegistryInterface;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\Models\PbxNode as PbxNodeModel;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\PbxNode;
use ApnTalk\LaravelFreeswitchEsl\Exceptions\PbxNotFoundException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Database-backed PBX node registry.
 *
 * This is the authoritative source for live PBX node inventory at runtime.
 * It queries the pbx_nodes table, eager-loads provider data for provider_code
 * enrichment, and converts Eloquent models to immutable PbxNode value objects.
 */
class DatabasePbxRegistry implements PbxRegistryInterface
{
    public function findById(int $id): PbxNode
    {
        $model = PbxNodeModel::query()->with('provider')->find($id);

        if ($model === null) {
            throw PbxNotFoundException::forId($id);
        }

        return $model->toValueObject();
    }

    public function findBySlug(string $slug): PbxNode
    {
        $model = PbxNodeModel::query()->with('provider')->where('slug', $slug)->first();

        if ($model === null) {
            throw PbxNotFoundException::forSlug($slug);
        }

        return $model->toValueObject();
    }

    public function allActive(): array
    {
        return PbxNodeModel::query()->with('provider')
            ->where('is_active', true)
            ->get()
            ->map(fn (PbxNodeModel $m) => $m->toValueObject())
            ->all();
    }

    public function allByCluster(string $cluster): array
    {
        return PbxNodeModel::query()->with('provider')
            ->where('is_active', true)
            ->where('cluster', $cluster)
            ->get()
            ->map(fn (PbxNodeModel $m) => $m->toValueObject())
            ->all();
    }

    public function allByTags(array $tags): array
    {
        if (empty($tags)) {
            return [];
        }

        $query = PbxNodeModel::query()->with('provider')->where('is_active', true);

        // Group tag conditions inside a single AND-wrapped OR block so the
        // active() scope is not bypassed by a top-level OR condition.
        $query->where(function ($q) use ($tags) {
            foreach ($tags as $i => $tag) {
                if ($i === 0) {
                    $q->whereJsonContains('tags_json', $tag);
                } else {
                    $q->orWhere(fn ($inner) => $inner->whereJsonContains('tags_json', $tag));
                }
            }
        });

        return $query
            ->get()
            ->map(fn (PbxNodeModel $m) => $m->toValueObject())
            ->unique(fn (PbxNode $n) => $n->id)
            ->values()
            ->all();
    }

    public function allByProvider(string $providerCode): array
    {
        return PbxNodeModel::query()->with('provider')
            ->where('is_active', true)
            ->whereHas('provider', fn (Builder $q) => $q->where('code', $providerCode))
            ->get()
            ->map(fn (PbxNodeModel $m) => $m->toValueObject())
            ->all();
    }
}
