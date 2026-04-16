<?php

namespace ApnTalk\LaravelFreeswitchEsl\Tests\Integration\ControlPlane;

use ApnTalk\LaravelFreeswitchEsl\ControlPlane\Models\PbxNode;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\Models\PbxProvider;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\Services\DatabasePbxRegistry;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\WorkerAssignment;
use ApnTalk\LaravelFreeswitchEsl\Exceptions\PbxNotFoundException;
use ApnTalk\LaravelFreeswitchEsl\Tests\TestCase;

class DatabasePbxRegistryTest extends TestCase
{
    private function createProvider(string $code = 'freeswitch'): PbxProvider
    {
        return PbxProvider::create([
            'code'         => $code,
            'name'         => ucfirst($code),
            'driver_class' => \ApnTalk\LaravelFreeswitchEsl\Drivers\FreeSwitchDriver::class,
            'is_active'    => true,
        ]);
    }

    private function createNode(PbxProvider $provider, array $overrides = []): PbxNode
    {
        return PbxNode::create(array_merge([
            'provider_id'         => $provider->id,
            'name'                => 'Test Node',
            'slug'                => 'test-node-' . uniqid(),
            'host'                => '10.0.0.1',
            'port'                => 8021,
            'username'            => '',
            'password_secret_ref' => 'ClueCon',
            'transport'           => 'tcp',
            'is_active'           => true,
        ], $overrides));
    }

    public function test_find_by_id_returns_node(): void
    {
        $provider = $this->createProvider();
        $model = $this->createNode($provider, ['slug' => 'primary-fs']);

        $registry = new DatabasePbxRegistry();
        $node = $registry->findById($model->id);

        $this->assertSame($model->id, $node->id);
        $this->assertSame('primary-fs', $node->slug);
        $this->assertSame('freeswitch', $node->providerCode);
    }

    public function test_find_by_id_throws_for_missing_node(): void
    {
        $registry = new DatabasePbxRegistry();

        $this->expectException(PbxNotFoundException::class);
        $registry->findById(999999);
    }

    public function test_find_by_slug_returns_node(): void
    {
        $provider = $this->createProvider();
        $this->createNode($provider, ['slug' => 'my-test-slug']);

        $registry = new DatabasePbxRegistry();
        $node = $registry->findBySlug('my-test-slug');

        $this->assertSame('my-test-slug', $node->slug);
    }

    public function test_find_by_slug_throws_for_missing_slug(): void
    {
        $registry = new DatabasePbxRegistry();

        $this->expectException(PbxNotFoundException::class);
        $registry->findBySlug('non-existent-slug');
    }

    public function test_all_active_returns_only_active_nodes(): void
    {
        $provider = $this->createProvider();
        $this->createNode($provider, ['slug' => 'active-1', 'is_active' => true]);
        $this->createNode($provider, ['slug' => 'active-2', 'is_active' => true]);
        $this->createNode($provider, ['slug' => 'inactive-1', 'is_active' => false]);

        $registry = new DatabasePbxRegistry();
        $nodes = $registry->allActive();

        $slugs = array_map(fn ($n) => $n->slug, $nodes);

        $this->assertContains('active-1', $slugs);
        $this->assertContains('active-2', $slugs);
        $this->assertNotContains('inactive-1', $slugs);
    }

    public function test_all_by_cluster_filters_correctly(): void
    {
        $provider = $this->createProvider();
        $this->createNode($provider, ['slug' => 'east-1', 'cluster' => 'us-east']);
        $this->createNode($provider, ['slug' => 'east-2', 'cluster' => 'us-east']);
        $this->createNode($provider, ['slug' => 'west-1', 'cluster' => 'us-west']);

        $registry = new DatabasePbxRegistry();
        $nodes = $registry->allByCluster('us-east');

        $slugs = array_map(fn ($n) => $n->slug, $nodes);

        $this->assertContains('east-1', $slugs);
        $this->assertContains('east-2', $slugs);
        $this->assertNotContains('west-1', $slugs);
    }

    public function test_all_by_tags_filters_correctly(): void
    {
        $provider = $this->createProvider();
        // Pass PHP arrays — the 'array' cast on the model will encode them to JSON
        $this->createNode($provider, ['slug' => 'prod-node', 'tags_json' => ['prod', 'primary']]);
        $this->createNode($provider, ['slug' => 'staging-node', 'tags_json' => ['staging']]);

        $registry = new DatabasePbxRegistry();
        $nodes = $registry->allByTags(['prod']);

        $slugs = array_map(fn ($n) => $n->slug, $nodes);

        $this->assertContains('prod-node', $slugs);
        $this->assertNotContains('staging-node', $slugs);
    }

    public function test_all_by_provider_returns_provider_nodes(): void
    {
        $fsProvider = $this->createProvider('freeswitch');
        $this->createNode($fsProvider, ['slug' => 'fs-node-1']);
        $this->createNode($fsProvider, ['slug' => 'fs-node-2']);

        $registry = new DatabasePbxRegistry();
        $nodes = $registry->allByProvider('freeswitch');

        $this->assertGreaterThanOrEqual(2, count($nodes));

        foreach ($nodes as $node) {
            $this->assertSame('freeswitch', $node->providerCode);
        }
    }

    public function test_value_object_is_immutable(): void
    {
        $provider = $this->createProvider();
        $model = $this->createNode($provider);

        $registry = new DatabasePbxRegistry();
        $node1 = $registry->findById($model->id);
        $node2 = $registry->findById($model->id);

        $this->assertNotSame($node1, $node2);
        $this->assertSame($node1->id, $node2->id);
    }
}
