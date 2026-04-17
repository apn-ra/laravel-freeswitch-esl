<?php

namespace ApnTalk\LaravelFreeswitchEsl\Tests\Unit\Console;

use ApnTalk\LaravelFreeswitchEsl\Console\Commands\FreeSwitchStatusCommand;
use PHPUnit\Framework\TestCase;

class FreeSwitchStatusCommandSignatureTest extends TestCase
{
    public function test_status_command_signature_does_not_advertise_unsupported_all_flag(): void
    {
        $command = new FreeSwitchStatusCommand;
        $signature = $this->commandSignature($command);

        $this->assertStringNotContainsString('{--all', $signature);
    }

    public function test_status_command_signature_keeps_supported_filters(): void
    {
        $command = new FreeSwitchStatusCommand;
        $signature = $this->commandSignature($command);

        $this->assertStringContainsString('{--pbx=', $signature);
        $this->assertStringContainsString('{--cluster=', $signature);
        $this->assertStringContainsString('{--provider=', $signature);
    }

    private function commandSignature(FreeSwitchStatusCommand $command): string
    {
        $reflection = new \ReflectionClass($command);
        $property = $reflection->getProperty('signature');
        $property->setAccessible(true);

        /** @var string $signature */
        $signature = $property->getValue($command);

        return $signature;
    }
}
