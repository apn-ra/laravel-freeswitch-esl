<?php

namespace ApnTalk\LaravelFreeswitchEsl\Tests\Integration\Console;

use ApnTalk\LaravelFreeswitchEsl\Tests\TestCase;
use Illuminate\Contracts\Console\Kernel;

class FreeSwitchStatusCommandTest extends TestCase
{
    public function test_status_command_is_registered_with_the_console_kernel(): void
    {
        /** @var Kernel $kernel */
        $kernel = $this->app->make(Kernel::class);

        $this->assertArrayHasKey('freeswitch:status', $kernel->all());
    }
}
