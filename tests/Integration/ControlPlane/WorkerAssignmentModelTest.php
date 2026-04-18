<?php

namespace ApnTalk\LaravelFreeswitchEsl\Tests\Integration\ControlPlane;

use ApnTalk\LaravelFreeswitchEsl\ControlPlane\Models\PbxNode;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\Models\PbxProvider;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\Models\WorkerAssignment;
use ApnTalk\LaravelFreeswitchEsl\Drivers\FreeSwitchDriver;
use ApnTalk\LaravelFreeswitchEsl\Tests\TestCase;

class WorkerAssignmentModelTest extends TestCase
{
    public function test_model_rejects_invalid_assignment_mode_before_persisting(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid worker_assignments.assignment_mode');

        WorkerAssignment::query()->create([
            'worker_name' => 'ingest-worker',
            'assignment_mode' => 'invalid-mode',
            'is_active' => true,
        ]);
    }

    public function test_model_allows_valid_assignment_mode_to_persist(): void
    {
        $provider = PbxProvider::query()->create([
            'code' => 'freeswitch',
            'name' => 'FreeSWITCH',
            'driver_class' => FreeSwitchDriver::class,
            'is_active' => true,
        ]);

        $node = PbxNode::query()->create([
            'provider_id' => $provider->id,
            'name' => 'Primary FS',
            'slug' => 'primary-fs',
            'host' => '127.0.0.1',
            'port' => 8021,
            'username' => '',
            'password_secret_ref' => 'ClueCon',
            'transport' => 'tcp',
            'is_active' => true,
        ]);

        $assignment = WorkerAssignment::query()->create([
            'worker_name' => 'ingest-worker',
            'assignment_mode' => 'node',
            'pbx_node_id' => $node->id,
            'is_active' => true,
        ]);

        $this->assertSame('node', $assignment->assignment_mode);
        $this->assertSame($node->id, $assignment->pbx_node_id);
    }
}
