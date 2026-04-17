<?php

namespace ApnTalk\LaravelFreeswitchEsl\Tests\Unit\ControlPlane\Services;

use ApnTalk\LaravelFreeswitchEsl\Contracts\PbxRegistryInterface;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\Services\WorkerAssignmentResolver;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\PbxNode;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\WorkerAssignment;
use ApnTalk\LaravelFreeswitchEsl\Exceptions\PbxNotFoundException;
use PHPUnit\Framework\TestCase;

class WorkerAssignmentResolverTest extends TestCase
{
    private function makeNode(int $id, string $slug, ?string $cluster = null, array $tags = []): PbxNode
    {
        return new PbxNode(
            id: $id,
            providerId: 1,
            providerCode: 'freeswitch',
            name: "Node {$id}",
            slug: $slug,
            host: '10.0.0.'.$id,
            port: 8021,
            username: '',
            passwordSecretRef: 'secret',
            transport: 'tcp',
            isActive: true,
            cluster: $cluster,
            tags: $tags,
        );
    }

    public function test_node_mode_returns_single_node(): void
    {
        $node = $this->makeNode(1, 'fs-primary');

        $registry = $this->createMock(PbxRegistryInterface::class);
        $registry->expects($this->once())
            ->method('findById')
            ->with(1)
            ->willReturn($node);

        $resolver = new WorkerAssignmentResolver($registry);
        $result = $resolver->resolveNodes(WorkerAssignment::forNode('w', 1));

        $this->assertCount(1, $result);
        $this->assertSame('fs-primary', $result[0]->slug);
    }

    public function test_cluster_mode_returns_all_cluster_nodes(): void
    {
        $nodes = [
            $this->makeNode(1, 'fs-1', 'us-east'),
            $this->makeNode(2, 'fs-2', 'us-east'),
        ];

        $registry = $this->createMock(PbxRegistryInterface::class);
        $registry->expects($this->once())
            ->method('allByCluster')
            ->with('us-east')
            ->willReturn($nodes);

        $resolver = new WorkerAssignmentResolver($registry);
        $result = $resolver->resolveNodes(WorkerAssignment::forCluster('w', 'us-east'));

        $this->assertCount(2, $result);
    }

    public function test_tag_mode_returns_tagged_nodes(): void
    {
        $nodes = [$this->makeNode(1, 'fs-1', null, ['prod'])];

        $registry = $this->createMock(PbxRegistryInterface::class);
        $registry->expects($this->once())
            ->method('allByTags')
            ->with(['prod'])
            ->willReturn($nodes);

        $resolver = new WorkerAssignmentResolver($registry);
        $result = $resolver->resolveNodes(WorkerAssignment::forTag('w', 'prod'));

        $this->assertCount(1, $result);
    }

    public function test_provider_mode_returns_provider_nodes(): void
    {
        $nodes = [$this->makeNode(1, 'fs-1')];

        $registry = $this->createMock(PbxRegistryInterface::class);
        $registry->expects($this->once())
            ->method('allByProvider')
            ->with('freeswitch')
            ->willReturn($nodes);

        $resolver = new WorkerAssignmentResolver($registry);
        $result = $resolver->resolveNodes(WorkerAssignment::forProvider('w', 'freeswitch'));

        $this->assertCount(1, $result);
    }

    public function test_all_active_mode_returns_all_active_nodes(): void
    {
        $nodes = [
            $this->makeNode(1, 'fs-1'),
            $this->makeNode(2, 'fs-2'),
            $this->makeNode(3, 'fs-3'),
        ];

        $registry = $this->createMock(PbxRegistryInterface::class);
        $registry->expects($this->once())
            ->method('allActive')
            ->willReturn($nodes);

        $resolver = new WorkerAssignmentResolver($registry);
        $result = $resolver->resolveNodes(WorkerAssignment::allActive('w'));

        $this->assertCount(3, $result);
    }

    public function test_node_mode_without_node_id_throws(): void
    {
        $registry = $this->createMock(PbxRegistryInterface::class);

        // Assignment with node mode but no pbxNodeId — simulate by creating directly
        $assignment = new WorkerAssignment(
            id: null,
            workerName: 'w',
            assignmentMode: WorkerAssignment::MODE_NODE,
            pbxNodeId: null,
        );

        $registry->expects($this->never())->method('findById');

        $resolver = new WorkerAssignmentResolver($registry);

        $this->expectException(PbxNotFoundException::class);
        $resolver->resolveNodes($assignment);
    }
}
