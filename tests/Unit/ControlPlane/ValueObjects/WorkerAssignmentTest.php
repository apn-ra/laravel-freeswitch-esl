<?php

namespace ApnTalk\LaravelFreeswitchEsl\Tests\Unit\ControlPlane\ValueObjects;

use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\WorkerAssignment;
use PHPUnit\Framework\TestCase;

class WorkerAssignmentTest extends TestCase
{
    public function test_for_node_sets_correct_mode(): void
    {
        $assignment = WorkerAssignment::forNode('worker-1', 42);

        $this->assertSame(WorkerAssignment::MODE_NODE, $assignment->assignmentMode);
        $this->assertSame(42, $assignment->pbxNodeId);
        $this->assertSame('worker-1', $assignment->workerName);
    }

    public function test_for_cluster_sets_correct_mode(): void
    {
        $assignment = WorkerAssignment::forCluster('worker-1', 'us-east');

        $this->assertSame(WorkerAssignment::MODE_CLUSTER, $assignment->assignmentMode);
        $this->assertSame('us-east', $assignment->cluster);
    }

    public function test_for_tag_sets_correct_mode(): void
    {
        $assignment = WorkerAssignment::forTag('worker-1', 'prod');

        $this->assertSame(WorkerAssignment::MODE_TAG, $assignment->assignmentMode);
        $this->assertSame('prod', $assignment->tag);
    }

    public function test_for_provider_sets_correct_mode(): void
    {
        $assignment = WorkerAssignment::forProvider('worker-1', 'freeswitch');

        $this->assertSame(WorkerAssignment::MODE_PROVIDER, $assignment->assignmentMode);
        $this->assertSame('freeswitch', $assignment->providerCode);
    }

    public function test_all_active_sets_correct_mode(): void
    {
        $assignment = WorkerAssignment::allActive('worker-1');

        $this->assertSame(WorkerAssignment::MODE_ALL_ACTIVE, $assignment->assignmentMode);
    }

    public function test_invalid_mode_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid assignment mode');

        new WorkerAssignment(
            id: null,
            workerName: 'w',
            assignmentMode: 'invalid-mode',
        );
    }

    public function test_from_record_maps_fields(): void
    {
        $assignment = WorkerAssignment::fromRecord([
            'id' => 1,
            'worker_name' => 'my-worker',
            'assignment_mode' => 'cluster',
            'pbx_node_id' => null,
            'provider_code' => null,
            'cluster' => 'us-east',
            'tag' => null,
            'is_active' => true,
        ]);

        $this->assertSame('my-worker', $assignment->workerName);
        $this->assertSame('cluster', $assignment->assignmentMode);
        $this->assertSame('us-east', $assignment->cluster);
        $this->assertTrue($assignment->isActive);
    }

    public function test_valid_modes_are_complete(): void
    {
        $expected = ['node', 'cluster', 'tag', 'provider', 'all-active'];

        $this->assertSame($expected, WorkerAssignment::VALID_MODES);
    }
}
