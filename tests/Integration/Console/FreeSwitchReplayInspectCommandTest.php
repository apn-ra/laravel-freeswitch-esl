<?php

namespace ApnTalk\LaravelFreeswitchEsl\Tests\Integration\Console;

use ApnTalk\LaravelFreeswitchEsl\Tests\TestCase;
use Illuminate\Contracts\Console\Kernel;

class FreeSwitchReplayInspectCommandTest extends TestCase
{
    public function test_replay_inspect_command_is_registered_with_the_console_kernel(): void
    {
        /** @var Kernel $kernel */
        $kernel = $this->app->make(Kernel::class);

        $this->assertArrayHasKey('freeswitch:replay:inspect', $kernel->all());
    }

    public function test_replay_inspect_command_exits_cleanly_when_replay_is_disabled(): void
    {
        $this->app['config']->set('freeswitch-esl.replay.enabled', false);

        $this->artisan('freeswitch:replay:inspect')
            ->expectsOutput('Replay capture is disabled. Set freeswitch-esl.replay.enabled = true to enable.')
            ->assertExitCode(0);
    }
}
