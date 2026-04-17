<?php

namespace ApnTalk\LaravelFreeswitchEsl\Tests;

use ApnTalk\LaravelFreeswitchEsl\Providers\FreeSwitchEslServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            FreeSwitchEslServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $replayToken = bin2hex(random_bytes(8));

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        $app['config']->set('freeswitch-esl.drivers', [
            'freeswitch' => \ApnTalk\LaravelFreeswitchEsl\Drivers\FreeSwitchDriver::class,
        ]);

        $app['config']->set('freeswitch-esl.secret_resolver.mode', 'plaintext');
        $app['config']->set('freeswitch-esl.replay.storage_path', sys_get_temp_dir() . '/laravel-freeswitch-esl-testbench-' . $replayToken . '.sqlite');
        $app['config']->set('freeswitch-esl.replay.checkpoint_storage_path', sys_get_temp_dir() . '/laravel-freeswitch-esl-testbench-checkpoints-' . $replayToken);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrations();
    }

    protected function runMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
