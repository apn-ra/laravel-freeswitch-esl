<?php

namespace ApnTalk\LaravelFreeswitchEsl\Tests\Integration\Console;

use ApnTalk\LaravelFreeswitchEsl\ControlPlane\Models\PbxConnectionProfile;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\Models\PbxNode;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\Models\PbxProvider;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\Models\WorkerAssignment;
use ApnTalk\LaravelFreeswitchEsl\Drivers\FreeSwitchDriver;
use ApnTalk\LaravelFreeswitchEsl\Events\MetricsRecorded;
use ApnTalk\LaravelFreeswitchEsl\Tests\TestCase;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Event;

class FreeSwitchValidateInstallCommandTest extends TestCase
{
    public function test_validate_install_command_is_registered_with_the_console_kernel(): void
    {
        $app = $this->app;
        $this->assertNotNull($app);
        /** @var Kernel $kernel */
        $kernel = $app->make(Kernel::class);

        $this->assertArrayHasKey('freeswitch:validate-install', $kernel->all());
    }

    public function test_validate_install_command_reports_machine_readable_install_posture_and_emits_metric(): void
    {
        Event::fake([MetricsRecorded::class]);
        $app = $this->app;
        $this->assertNotNull($app);
        $app['config']->set('freeswitch-esl.observability.metrics.driver', 'event');

        /** @var Kernel $kernel */
        $kernel = $app->make(Kernel::class);
        $exitCode = $kernel->call('freeswitch:validate-install', [
            '--json' => true,
        ]);

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode(trim($kernel->output()), true);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($decoded);
        $this->assertSame('install_validation', $decoded['report_surface']);
        $this->assertTrue($decoded['passed']);
        $this->assertSame('event', $decoded['config']['metrics_driver']);
        $this->assertTrue($decoded['schema']['pbx_providers']);
        $this->assertTrue($decoded['schema']['pbx_nodes']);
        $this->assertTrue($decoded['schema']['pbx_connection_profiles']);
        $this->assertTrue($decoded['schema']['worker_assignments']);
        $this->assertTrue($decoded['commands']['freeswitch:validate-install']);
        $this->assertTrue($decoded['metrics']['validation_metric_emitted']);
        $this->assertArrayNotHasKey('provider_freeswitch_present', $decoded['example'] ?? []);

        Event::assertDispatched(MetricsRecorded::class, function (MetricsRecorded $event): bool {
            return $event->name === 'freeswitch_esl.install.validation'
                && $event->type === 'counter'
                && ($event->tags['surface'] ?? null) === 'validate_install';
        });
    }

    public function test_validate_install_command_can_verify_documented_example_seed_shape(): void
    {
        $provider = PbxProvider::query()->create([
            'code' => 'freeswitch',
            'name' => 'FreeSWITCH',
            'driver_class' => FreeSwitchDriver::class,
            'is_active' => true,
        ]);
        /** @var int $providerId */
        $providerId = (int) $provider->getKey();

        PbxConnectionProfile::query()->create([
            'provider_id' => $providerId,
            'name' => 'default',
        ]);

        $node = PbxNode::query()->create([
            'provider_id' => $providerId,
            'name' => 'Primary FS',
            'slug' => 'primary-fs',
            'host' => '127.0.0.1',
            'port' => 8021,
            'username' => '',
            'password_secret_ref' => 'ClueCon',
            'transport' => 'tcp',
            'is_active' => true,
        ]);
        /** @var int $nodeId */
        $nodeId = (int) $node->getKey();

        WorkerAssignment::query()->create([
            'worker_name' => 'ingest-worker',
            'assignment_mode' => 'node',
            'pbx_node_id' => $nodeId,
            'provider_code' => 'freeswitch',
            'is_active' => true,
        ]);

        $app = $this->app;
        $this->assertNotNull($app);
        /** @var Kernel $kernel */
        $kernel = $app->make(Kernel::class);
        $exitCode = $kernel->call('freeswitch:validate-install', [
            '--example' => true,
            '--json' => true,
        ]);

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode(trim($kernel->output()), true);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($decoded);
        $this->assertTrue($decoded['passed']);
        $this->assertSame([
            'provider_freeswitch_present' => true,
            'node_primary_fs_present' => true,
            'profile_default_present' => true,
            'worker_ingest_worker_present' => true,
        ], $decoded['example']);
    }

    public function test_validate_install_command_fails_when_example_seed_shape_is_missing(): void
    {
        $app = $this->app;
        $this->assertNotNull($app);
        /** @var Kernel $kernel */
        $kernel = $app->make(Kernel::class);
        $exitCode = $kernel->call('freeswitch:validate-install', [
            '--example' => true,
            '--json' => true,
        ]);

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode(trim($kernel->output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertIsArray($decoded);
        $this->assertFalse($decoded['passed']);
        $this->assertSame([
            'provider_freeswitch_present' => false,
            'node_primary_fs_present' => false,
            'profile_default_present' => false,
            'worker_ingest_worker_present' => false,
        ], $decoded['example']);
    }
}
