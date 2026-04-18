<?php

namespace ApnTalk\LaravelFreeswitchEsl\Tests\Integration\Providers;

use Apntalk\EslReplay\Checkpoint\ReplayCheckpointRepository;
use Apntalk\EslReplay\Contracts\ReplayArtifactStoreInterface;
use Apntalk\EslReplay\Contracts\ReplayCheckpointStoreInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\ConnectionFactoryInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\ConnectionResolverInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\HealthReporterInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\MetricsRecorderInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\PbxRegistryInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\ProviderDriverRegistryInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\RuntimeRunnerInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\SecretResolverInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\WorkerAssignmentResolverInterface;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\Services\ConnectionProfileResolver;
use ApnTalk\LaravelFreeswitchEsl\Facades\FreeSwitchEslManager;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslCoreConnectionFactory;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslReactRuntimeBootstrapInputFactory;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslReactRuntimeRunnerAdapter;
use ApnTalk\LaravelFreeswitchEsl\Integration\NonLiveRuntimeRunner;
use ApnTalk\LaravelFreeswitchEsl\Integration\Replay\WorkerReplayCheckpointManager;
use ApnTalk\LaravelFreeswitchEsl\Observability\NullMetricsRecorder;
use ApnTalk\LaravelFreeswitchEsl\Tests\TestCase;

class FreeSwitchEslServiceProviderTest extends TestCase
{
    public function test_pbx_registry_is_bound(): void
    {
        $this->assertInstanceOf(
            PbxRegistryInterface::class,
            $this->app->make(PbxRegistryInterface::class)
        );
    }

    public function test_provider_driver_registry_is_bound(): void
    {
        $this->assertInstanceOf(
            ProviderDriverRegistryInterface::class,
            $this->app->make(ProviderDriverRegistryInterface::class)
        );
    }

    public function test_secret_resolver_is_bound(): void
    {
        $this->assertInstanceOf(
            SecretResolverInterface::class,
            $this->app->make(SecretResolverInterface::class)
        );
    }

    public function test_connection_resolver_is_bound(): void
    {
        $this->assertInstanceOf(
            ConnectionResolverInterface::class,
            $this->app->make(ConnectionResolverInterface::class)
        );
    }

    public function test_connection_factory_is_bound(): void
    {
        $this->assertInstanceOf(
            ConnectionFactoryInterface::class,
            $this->app->make(ConnectionFactoryInterface::class)
        );
    }

    public function test_connection_factory_binding_uses_current_esl_core_adapter(): void
    {
        $factory = $this->app->make(ConnectionFactoryInterface::class);

        $this->assertInstanceOf(EslCoreConnectionFactory::class, $factory);
    }

    public function test_connection_factory_is_singleton(): void
    {
        $a = $this->app->make(ConnectionFactoryInterface::class);
        $b = $this->app->make(ConnectionFactoryInterface::class);

        $this->assertSame($a, $b);
    }

    public function test_runtime_runner_is_bound(): void
    {
        $this->assertInstanceOf(
            RuntimeRunnerInterface::class,
            $this->app->make(RuntimeRunnerInterface::class)
        );
    }

    public function test_runtime_runner_binding_uses_esl_react_adapter_by_default(): void
    {
        $runner = $this->app->make(RuntimeRunnerInterface::class);

        $this->assertInstanceOf(EslReactRuntimeRunnerAdapter::class, $runner);
    }

    public function test_runtime_runner_binding_can_use_non_live_fallback(): void
    {
        $this->app['config']->set('freeswitch-esl.runtime.runner', 'non-live');
        $this->app->forgetInstance(RuntimeRunnerInterface::class);

        $runner = $this->app->make(RuntimeRunnerInterface::class);

        $this->assertInstanceOf(NonLiveRuntimeRunner::class, $runner);
    }

    public function test_esl_react_runtime_bootstrap_input_factory_is_bound(): void
    {
        $this->assertInstanceOf(
            EslReactRuntimeBootstrapInputFactory::class,
            $this->app->make(EslReactRuntimeBootstrapInputFactory::class)
        );
    }

    public function test_runtime_runner_is_singleton(): void
    {
        $a = $this->app->make(RuntimeRunnerInterface::class);
        $b = $this->app->make(RuntimeRunnerInterface::class);

        $this->assertSame($a, $b);
    }

    public function test_worker_assignment_resolver_is_bound(): void
    {
        $this->assertInstanceOf(
            WorkerAssignmentResolverInterface::class,
            $this->app->make(WorkerAssignmentResolverInterface::class)
        );
    }

    public function test_health_reporter_is_bound(): void
    {
        $this->assertInstanceOf(
            HealthReporterInterface::class,
            $this->app->make(HealthReporterInterface::class)
        );
    }

    public function test_metrics_recorder_is_bound_to_no_op_default(): void
    {
        $this->assertInstanceOf(
            NullMetricsRecorder::class,
            $this->app->make(MetricsRecorderInterface::class)
        );
    }

    public function test_connection_profile_resolver_is_bound(): void
    {
        $this->assertInstanceOf(
            ConnectionProfileResolver::class,
            $this->app->make(ConnectionProfileResolver::class)
        );
    }

    public function test_manager_is_bound(): void
    {
        $this->assertInstanceOf(
            FreeSwitchEslManager::class,
            $this->app->make(FreeSwitchEslManager::class)
        );
    }

    public function test_freeswitch_driver_is_registered_in_provider_registry(): void
    {
        /** @var ProviderDriverRegistryInterface $registry */
        $registry = $this->app->make(ProviderDriverRegistryInterface::class);

        $this->assertTrue($registry->has('freeswitch'));
        $this->assertContains('freeswitch', $registry->registeredCodes());
    }

    public function test_config_is_merged(): void
    {
        $config = $this->app['config']->get('freeswitch-esl');

        $this->assertIsArray($config);
        $this->assertArrayHasKey('drivers', $config);
        $this->assertArrayHasKey('retry_defaults', $config);
        $this->assertArrayHasKey('drain_defaults', $config);
        $this->assertArrayHasKey('health', $config);
        $this->assertArrayHasKey('http', $config);
        $this->assertArrayHasKey('replay', $config);
    }

    public function test_config_has_correct_default_driver(): void
    {
        $this->assertSame('freeswitch', $this->app['config']->get('freeswitch-esl.default_driver'));
    }

    public function test_replay_store_contract_is_bound(): void
    {
        $this->assertTrue($this->app->bound(ReplayArtifactStoreInterface::class));
    }

    public function test_replay_checkpoint_store_contract_is_bound(): void
    {
        $this->assertTrue($this->app->bound(ReplayCheckpointStoreInterface::class));
    }

    public function test_replay_checkpoint_repository_is_bound(): void
    {
        $this->assertInstanceOf(
            ReplayCheckpointRepository::class,
            $this->app->make(ReplayCheckpointRepository::class)
        );
    }

    public function test_worker_replay_checkpoint_manager_is_bound(): void
    {
        $this->assertInstanceOf(
            WorkerReplayCheckpointManager::class,
            $this->app->make(WorkerReplayCheckpointManager::class)
        );
    }

    public function test_migrations_are_in_expected_path(): void
    {
        $migrationPath = realpath(__DIR__.'/../../../database/migrations');

        $this->assertDirectoryExists($migrationPath);

        $files = glob($migrationPath.'/*.php');
        $this->assertNotEmpty($files);
    }
}
