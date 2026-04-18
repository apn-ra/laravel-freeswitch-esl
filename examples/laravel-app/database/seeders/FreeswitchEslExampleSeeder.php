<?php

namespace Database\Seeders;

use ApnTalk\LaravelFreeswitchEsl\ControlPlane\Models\PbxConnectionProfile;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\Models\PbxNode;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\Models\PbxProvider;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\Models\WorkerAssignment;
use ApnTalk\LaravelFreeswitchEsl\Drivers\FreeSwitchDriver;
use Illuminate\Database\Seeder;

class FreeswitchEslExampleSeeder extends Seeder
{
    public function run(): void
    {
        $provider = PbxProvider::query()->updateOrCreate(
            ['code' => 'freeswitch'],
            [
                'name' => 'FreeSWITCH',
                'driver_class' => FreeSwitchDriver::class,
                'is_active' => true,
            ],
        );

        $profile = PbxConnectionProfile::query()->updateOrCreate(
            [
                'provider_id' => $provider->id,
                'name' => 'default',
            ],
            [
                'retry_policy_json' => [
                    'max_attempts' => 5,
                    'initial_delay_ms' => 1000,
                    'backoff_factor' => 2.0,
                    'max_delay_ms' => 60000,
                    'jitter' => true,
                ],
                'drain_policy_json' => [
                    'timeout_ms' => 30000,
                    'max_inflight' => 100,
                    'check_interval_ms' => 500,
                ],
                'subscription_profile_json' => [
                    'event_names' => ['CHANNEL_CREATE'],
                ],
                'worker_profile_json' => [
                    'heartbeat_interval_seconds' => 30,
                    'checkpoint_interval_seconds' => 60,
                ],
            ],
        );

        $node = PbxNode::query()->updateOrCreate(
            ['slug' => 'primary-fs'],
            [
                'provider_id' => $provider->id,
                'name' => 'Primary FS',
                'host' => env('FREESWITCH_ESL_HOST', '127.0.0.1'),
                'port' => (int) env('FREESWITCH_ESL_PORT', 8021),
                'username' => '',
                'password_secret_ref' => env('FREESWITCH_ESL_PASSWORD', 'ClueCon'),
                'transport' => 'tcp',
                'is_active' => true,
                'cluster' => 'example',
                'region' => 'local',
                'tags_json' => ['example', 'primary'],
                'health_status' => 'unknown',
            ],
        );

        WorkerAssignment::query()->updateOrCreate(
            [
                'worker_name' => 'ingest-worker',
                'assignment_mode' => 'node',
            ],
            [
                'pbx_node_id' => $node->id,
                'provider_code' => $provider->code,
                'is_active' => true,
            ],
        );

        $this->command?->info(sprintf(
            'Seeded provider [%s], node [%s], profile [%s], and worker [%s].',
            $provider->code,
            $node->slug,
            $profile->name,
            'ingest-worker',
        ));
    }
}
