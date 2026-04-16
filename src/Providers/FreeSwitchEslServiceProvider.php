<?php

namespace ApnTalk\LaravelFreeswitchEsl\Providers;

use ApnTalk\LaravelFreeswitchEsl\Contracts\ConnectionFactoryInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\ConnectionResolverInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\HealthReporterInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\PbxRegistryInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\ProviderDriverRegistryInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\SecretResolverInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\WorkerAssignmentResolverInterface;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\Services\ConnectionProfileResolver;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\Services\ConnectionResolver;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\Services\DatabasePbxRegistry;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\Services\ProviderDriverRegistry;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\Services\SecretResolver;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\Services\WorkerAssignmentResolver;
use ApnTalk\LaravelFreeswitchEsl\Health\HealthReporter;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslCoreCommandFactory;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslCoreConnectionFactory;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslCoreEventBridge;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslCorePipelineFactory;
use Apntalk\EslCore\Contracts\InboundConnectionFactoryInterface;
use Apntalk\EslCore\Contracts\TransportFactoryInterface;
use Apntalk\EslCore\Inbound\InboundConnectionFactory;
use Apntalk\EslCore\Transport\SocketTransportFactory;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;

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
            __DIR__ . '/../../config/freeswitch-esl.php',
            'freeswitch-esl'
        );

        $this->registerSecretResolver();
        $this->registerPbxRegistry();
        $this->registerProviderDriverRegistry();
        $this->registerConnectionProfileResolver();
        $this->registerConnectionResolver();
        $this->registerConnectionFactory();
        $this->registerWorkerAssignmentResolver();
        $this->registerHealthReporter();
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

    private function registerWorkerAssignmentResolver(): void
    {
        $this->app->singleton(WorkerAssignmentResolverInterface::class, function ($app) {
            return new WorkerAssignmentResolver($app->make(PbxRegistryInterface::class));
        });
    }

    private function registerHealthReporter(): void
    {
        $this->app->singleton(HealthReporterInterface::class, function ($app) {
            return new HealthReporter(
                pbxRegistry: $app->make(PbxRegistryInterface::class),
                heartbeatTimeoutSeconds: $app['config']->get(
                    'freeswitch-esl.health.heartbeat_timeout_seconds',
                    60
                ),
            );
        });
    }

    private function registerManager(): void
    {
        $this->app->singleton(\ApnTalk\LaravelFreeswitchEsl\Facades\FreeSwitchEslManager::class, function ($app) {
            return new \ApnTalk\LaravelFreeswitchEsl\Facades\FreeSwitchEslManager(
                registry: $app->make(PbxRegistryInterface::class),
                resolver: $app->make(ConnectionResolverInterface::class),
                healthReporter: $app->make(HealthReporterInterface::class),
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
        $drivers = $this->app['config']->get('freeswitch-esl.drivers', []);

        foreach ($drivers as $code => $driverClass) {
            if (is_string($code) && is_string($driverClass)) {
                $registry->register($code, $driverClass);
            }
        }
    }

    private function publishConfig(): void
    {
        $this->publishes([
            __DIR__ . '/../../config/freeswitch-esl.php' => config_path('freeswitch-esl.php'),
        ], 'freeswitch-esl-config');
    }

    private function publishMigrations(): void
    {
        $this->publishes([
            __DIR__ . '/../../database/migrations' => database_path('migrations'),
        ], 'freeswitch-esl-migrations');
    }

    private function registerCommands(): void
    {
        $this->commands([
            \ApnTalk\LaravelFreeswitchEsl\Console\Commands\FreeSwitchPingCommand::class,
            \ApnTalk\LaravelFreeswitchEsl\Console\Commands\FreeSwitchStatusCommand::class,
            \ApnTalk\LaravelFreeswitchEsl\Console\Commands\FreeSwitchWorkerCommand::class,
            \ApnTalk\LaravelFreeswitchEsl\Console\Commands\FreeSwitchHealthCommand::class,
            \ApnTalk\LaravelFreeswitchEsl\Console\Commands\FreeSwitchReplayInspectCommand::class,
        ]);
    }
}
