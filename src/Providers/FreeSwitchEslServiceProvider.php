<?php

namespace ApnTalk\LaravelFreeswitchEsl\Providers;

use Apntalk\EslCore\Contracts\InboundConnectionFactoryInterface;
use Apntalk\EslCore\Contracts\TransportFactoryInterface;
use Apntalk\EslCore\Inbound\InboundConnectionFactory;
use Apntalk\EslCore\Transport\SocketTransportFactory;
use Apntalk\EslReact\AsyncEslRuntime;
use Apntalk\EslReact\Contracts\RuntimeRunnerInterface as EslReactRuntimeRunnerInterface;
use Apntalk\EslReplay\Checkpoint\ReplayCheckpointRepository;
use Apntalk\EslReplay\Contracts\ReplayArtifactStoreInterface;
use Apntalk\EslReplay\Contracts\ReplayCheckpointStoreInterface;
use ApnTalk\LaravelFreeswitchEsl\Console\Commands\FreeSwitchHealthCommand;
use ApnTalk\LaravelFreeswitchEsl\Console\Commands\FreeSwitchPingCommand;
use ApnTalk\LaravelFreeswitchEsl\Console\Commands\FreeSwitchReplayInspectCommand;
use ApnTalk\LaravelFreeswitchEsl\Console\Commands\FreeSwitchStatusCommand;
use ApnTalk\LaravelFreeswitchEsl\Console\Commands\FreeSwitchWorkerCheckpointStatusCommand;
use ApnTalk\LaravelFreeswitchEsl\Console\Commands\FreeSwitchWorkerCommand;
use ApnTalk\LaravelFreeswitchEsl\Console\Commands\FreeSwitchWorkerStatusCommand;
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
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\Services\ConnectionResolver;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\Services\DatabasePbxRegistry;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\Services\ProviderDriverRegistry;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\Services\SecretResolver;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\Services\WorkerAssignmentResolver;
use ApnTalk\LaravelFreeswitchEsl\Facades\FreeSwitchEslManager;
use ApnTalk\LaravelFreeswitchEsl\Health\HealthReporter;
use ApnTalk\LaravelFreeswitchEsl\Health\HealthSummaryBuilder;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslCoreCommandFactory;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslCoreConnectionFactory;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslCoreEventBridge;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslCorePipelineFactory;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslReactRuntimeBootstrapInputFactory;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslReactRuntimeRunnerAdapter;
use ApnTalk\LaravelFreeswitchEsl\Integration\NonLiveRuntimeRunner;
use ApnTalk\LaravelFreeswitchEsl\Integration\Replay\ReplayCaptureSinkFactory;
use ApnTalk\LaravelFreeswitchEsl\Integration\Replay\ReplayCheckpointStoreFactory;
use ApnTalk\LaravelFreeswitchEsl\Integration\Replay\ReplayStoreFactory;
use ApnTalk\LaravelFreeswitchEsl\Integration\Replay\WorkerReplayCheckpointManager;
use ApnTalk\LaravelFreeswitchEsl\Observability\EventMetricsRecorder;
use ApnTalk\LaravelFreeswitchEsl\Observability\LogMetricsRecorder;
use ApnTalk\LaravelFreeswitchEsl\Observability\NullMetricsRecorder;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

/**
 * Main Laravel service provider for apntalk/laravel-freeswitch-esl.
 *
 * This provider:
 *   - publishes config and migrations
 *   - registers all control-plane service bindings in the container
 *   - boots provider drivers from config
 *   - registers artisan commands
 *
 * Boot order:
 *   1. Bindings registered in register()
 *   2. Drivers loaded and registered in boot()
 *   3. Commands registered in boot()
 *   4. Migrations published in boot()
 */
class FreeSwitchEslServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../../config/freeswitch-esl.php',
            'freeswitch-esl'
        );

        $this->registerSecretResolver();
        $this->registerPbxRegistry();
        $this->registerProviderDriverRegistry();
        $this->registerConnectionProfileResolver();
        $this->registerConnectionResolver();
        $this->registerConnectionFactory();
        $this->registerReplayIntegration();
        $this->registerRuntimeRunner();
        $this->registerWorkerAssignmentResolver();
        $this->registerMetricsRecorder();
        $this->registerHealthReporter();
        $this->registerHealthSummaryBuilder();
        $this->registerManager();
        $this->registerEslCoreIntegration();
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishConfig();
            $this->publishMigrations();
            $this->registerCommands();
        }

        $this->bootProviderDrivers();
        $this->bootHttpHealthRoutes();
    }

    // -------------------------------------------------------------------------
    // Binding registrations
    // -------------------------------------------------------------------------

    private function registerSecretResolver(): void
    {
        $this->app->singleton(SecretResolverInterface::class, function ($app) {
            $config = $app['config']->get('freeswitch-esl.secret_resolver', []);
            $mode = $config['mode'] ?? 'plaintext';
            $customClass = $config['resolver_class'] ?? null;

            $customResolver = null;

            if ($mode === 'custom' && $customClass !== null) {
                $customResolver = $app->make($customClass);
            }

            return new SecretResolver($mode, $customResolver);
        });
    }

    private function registerPbxRegistry(): void
    {
        $this->app->singleton(PbxRegistryInterface::class, DatabasePbxRegistry::class);
    }

    private function registerProviderDriverRegistry(): void
    {
        $this->app->singleton(ProviderDriverRegistryInterface::class, function ($app) {
            return new ProviderDriverRegistry($app);
        });
    }

    private function registerConnectionProfileResolver(): void
    {
        $this->app->singleton(ConnectionProfileResolver::class, function ($app) {
            $config = $app['config'];

            return new ConnectionProfileResolver(
                retryDefaults: $config->get('freeswitch-esl.retry_defaults', []),
                drainDefaults: $config->get('freeswitch-esl.drain_defaults', []),
            );
        });
    }

    private function registerConnectionResolver(): void
    {
        $this->app->singleton(ConnectionResolverInterface::class, function ($app) {
            return new ConnectionResolver(
                pbxRegistry: $app->make(PbxRegistryInterface::class),
                driverRegistry: $app->make(ProviderDriverRegistryInterface::class),
                secretResolver: $app->make(SecretResolverInterface::class),
                profileResolver: $app->make(ConnectionProfileResolver::class),
            );
        });
    }

    private function registerConnectionFactory(): void
    {
        $this->app->singleton(ConnectionFactoryInterface::class, function ($app) {
            return new EslCoreConnectionFactory(
                commandFactory: $app->make(EslCoreCommandFactory::class),
                pipelineFactory: $app->make(EslCorePipelineFactory::class),
                transportFactory: $app->make(TransportFactoryInterface::class),
            );
        });
    }

    private function registerRuntimeRunner(): void
    {
        $this->app->singleton(EslReactRuntimeBootstrapInputFactory::class, function ($app) {
            return new EslReactRuntimeBootstrapInputFactory(
                connectorOptions: $app['config']->get('freeswitch-esl.runtime.react.connector_options', []),
                replayCaptureSinkFactory: $app->bound(ReplayCaptureSinkFactory::class)
                    ? $app->make(ReplayCaptureSinkFactory::class)
                    : null,
                replayCaptureEnabled: (bool) $app['config']->get('freeswitch-esl.replay.enabled', false),
            );
        });

        $this->app->singleton(EslReactRuntimeRunnerInterface::class, function () {
            return AsyncEslRuntime::runner();
        });

        $this->app->singleton(RuntimeRunnerInterface::class, function ($app) {
            $runner = $app['config']->get('freeswitch-esl.runtime.runner', 'esl-react');

            return match ($runner) {
                'esl-react', 'esl_react' => new EslReactRuntimeRunnerAdapter(
                    runner: $app->make(EslReactRuntimeRunnerInterface::class),
                    inputFactory: $app->make(EslReactRuntimeBootstrapInputFactory::class),
                ),
                'non-live', 'non_live' => new NonLiveRuntimeRunner,
                default => throw new \InvalidArgumentException(sprintf(
                    'Unsupported freeswitch-esl.runtime.runner [%s]. Supported values are [esl-react] and [non-live].',
                    is_scalar($runner) ? (string) $runner : get_debug_type($runner),
                )),
            };
        });
    }

    private function registerWorkerAssignmentResolver(): void
    {
        $this->app->singleton(WorkerAssignmentResolverInterface::class, function ($app) {
            return new WorkerAssignmentResolver($app->make(PbxRegistryInterface::class));
        });
    }

    private function registerMetricsRecorder(): void
    {
        $this->app->singleton(MetricsRecorderInterface::class, function ($app) {
            $driver = $app['config']->get('freeswitch-esl.observability.metrics.driver', 'log');

            return match ($driver) {
                'log' => new LogMetricsRecorder(
                    logger: $app->make(LoggerInterface::class),
                    level: (string) $app['config']->get('freeswitch-esl.observability.metrics.log_level', 'info'),
                ),
                'event' => new EventMetricsRecorder(
                    events: $app->make(Dispatcher::class),
                ),
                'null' => new NullMetricsRecorder,
                default => throw new \InvalidArgumentException(sprintf(
                    'Unsupported freeswitch-esl.observability.metrics.driver [%s]. Supported values are [log], [event], and [null].',
                    is_scalar($driver) ? (string) $driver : get_debug_type($driver),
                )),
            };
        });
    }

    private function registerHealthReporter(): void
    {
        $this->app->singleton(HealthReporterInterface::class, function ($app) {
            return new HealthReporter(
                pbxRegistry: $app->make(PbxRegistryInterface::class),
                metrics: $app->make(MetricsRecorderInterface::class),
                heartbeatTimeoutSeconds: $app['config']->get(
                    'freeswitch-esl.health.heartbeat_timeout_seconds',
                    60
                ),
            );
        });
    }

    private function registerHealthSummaryBuilder(): void
    {
        $this->app->singleton(HealthSummaryBuilder::class);
    }

    private function registerManager(): void
    {
        $this->app->singleton(FreeSwitchEslManager::class, function ($app) {
            return new FreeSwitchEslManager(
                registry: $app->make(PbxRegistryInterface::class),
                resolver: $app->make(ConnectionResolverInterface::class),
                healthReporter: $app->make(HealthReporterInterface::class),
            );
        });
    }

    private function registerReplayIntegration(): void
    {
        $this->app->singleton(ReplayStoreFactory::class);
        $this->app->singleton(ReplayCheckpointStoreFactory::class);

        $this->app->singleton(ReplayArtifactStoreInterface::class, function ($app) {
            return $app->make(ReplayStoreFactory::class)
                ->make($app['config']->get('freeswitch-esl.replay', []));
        });

        $this->app->singleton(ReplayCheckpointStoreInterface::class, function ($app) {
            return $app->make(ReplayCheckpointStoreFactory::class)
                ->make($app['config']->get('freeswitch-esl.replay', []));
        });

        $this->app->singleton(ReplayCheckpointRepository::class, function ($app) {
            return new ReplayCheckpointRepository(
                $app->make(ReplayCheckpointStoreInterface::class),
            );
        });

        $this->app->singleton(ReplayCaptureSinkFactory::class, function ($app) {
            return new ReplayCaptureSinkFactory(
                store: $app->make(ReplayArtifactStoreInterface::class),
                logger: $app->make(LoggerInterface::class),
            );
        });

        $this->app->singleton(WorkerReplayCheckpointManager::class, function ($app) {
            $replayConfig = $app['config']->get('freeswitch-esl.replay', []);

            return new WorkerReplayCheckpointManager(
                artifactStore: $app->make(ReplayArtifactStoreInterface::class),
                checkpointRepository: $app->make(ReplayCheckpointRepository::class),
                logger: $app->make(LoggerInterface::class),
                enabled: (bool) $app['config']->get('freeswitch-esl.replay.enabled', false),
                replayStoreDriver: is_string($replayConfig['store_driver'] ?? null) ? $replayConfig['store_driver'] : null,
                replayStoragePath: is_string($replayConfig['storage_path'] ?? null) ? $replayConfig['storage_path'] : null,
                retentionDays: (int) ($replayConfig['retention_days'] ?? 7),
            );
        });
    }

    private function registerEslCoreIntegration(): void
    {
        // SocketTransportFactory — stable public transport construction seam
        $this->app->singleton(TransportFactoryInterface::class, SocketTransportFactory::class);

        // InboundConnectionFactory — stable accepted-stream bootstrap seam
        $this->app->singleton(InboundConnectionFactoryInterface::class, function ($app) {
            return new InboundConnectionFactory(
                $app->make(TransportFactoryInterface::class),
            );
        });

        // EslCoreCommandFactory — builds typed esl-core command objects
        $this->app->singleton(EslCoreCommandFactory::class);

        // EslCorePipelineFactory — creates InboundPipeline instances per session
        $this->app->singleton(EslCorePipelineFactory::class);

        // EslCoreEventBridge — converts decoded messages to Laravel events
        $this->app->singleton(EslCoreEventBridge::class, function ($app) {
            return new EslCoreEventBridge($app->make(Dispatcher::class));
        });
    }

    // -------------------------------------------------------------------------
    // Boot helpers
    // -------------------------------------------------------------------------

    private function bootProviderDrivers(): void
    {
        /** @var ProviderDriverRegistryInterface $registry */
        $registry = $this->app->make(ProviderDriverRegistryInterface::class);
        $drivers = $this->app->make('config')->get('freeswitch-esl.drivers', []);

        foreach ($drivers as $code => $driverClass) {
            if (is_string($code) && is_string($driverClass)) {
                $registry->register($code, $driverClass);
            }
        }
    }

    private function publishConfig(): void
    {
        $this->publishes([
            __DIR__.'/../../config/freeswitch-esl.php' => config_path('freeswitch-esl.php'),
        ], 'freeswitch-esl-config');
    }

    private function publishMigrations(): void
    {
        $this->publishes([
            __DIR__.'/../../database/migrations' => database_path('migrations'),
        ], 'freeswitch-esl-migrations');
    }

    private function bootHttpHealthRoutes(): void
    {
        if ($this->app['config']->get('freeswitch-esl.http.health.enabled', true) !== true) {
            return;
        }

        $this->loadRoutesFrom(__DIR__.'/../../routes/freeswitch-esl-health.php');
    }

    private function registerCommands(): void
    {
        $this->commands([
            FreeSwitchPingCommand::class,
            FreeSwitchStatusCommand::class,
            FreeSwitchWorkerCommand::class,
            FreeSwitchWorkerStatusCommand::class,
            FreeSwitchWorkerCheckpointStatusCommand::class,
            FreeSwitchHealthCommand::class,
            FreeSwitchReplayInspectCommand::class,
        ]);
    }
}
