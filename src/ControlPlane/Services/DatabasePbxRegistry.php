<?php

namespace ApnTalk\LaravelFreeswitchEsl\ControlPlane\Services;

use ApnTalk\LaravelFreeswitchEsl\Contracts\PbxRegistryInterface;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\Models\PbxNode as PbxNodeModel;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\PbxNode;
use ApnTalk\LaravelFreeswitchEsl\Exceptions\PbxNotFoundException;

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
        $model = PbxNodeModel::with('provider')->find($id);

        if ($model === null) {
            throw PbxNotFoundException::forId($id);
        }

        return $model->toValueObject();
    }

    public function findBySlug(string $slug): PbxNode
    {
        $model = PbxNodeModel::with('provider')->where('slug', $slug)->first();

        if ($model === null) {
            throw PbxNotFoundException::forSlug($slug);
        }

        return $model->toValueObject();
    }

    public function allActive(): array
    {
        return PbxNodeModel::with('provider')
            ->active()
            ->get()
            ->map(fn (PbxNodeModel $m) => $m->toValueObject())
            ->all();
    }

    public function allByCluster(string $cluster): array
    {
        return PbxNodeModel::with('provider')
            ->active()
            ->inCluster($cluster)
            ->get()
            ->map(fn (PbxNodeModel $m) => $m->toValueObject())
            ->all();
    }

    public function allByTags(array $tags): array
    {
        if (empty($tags)) {
            return [];
        }

        $query = PbxNodeModel::with('provider')->active();

        // Group tag conditions inside a single AND-wrapped OR block so the
        // active() scope is not bypassed by a top-level OR condition.
        $query->where(function ($q) use ($tags) {
            foreach ($tags as $i => $tag) {
                if ($i === 0) {
                    $q->withTag($tag);
                } else {
                    $q->orWhere(fn ($inner) => $inner->withTag($tag));
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
        return PbxNodeModel::with('provider')
            ->active()
            ->forProvider($providerCode)
            ->get()
            ->map(fn (PbxNodeModel $m) => $m->toValueObject())
            ->all();
    }
}
