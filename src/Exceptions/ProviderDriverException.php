<?php

namespace ApnTalk\LaravelFreeswitchEsl\Exceptions;

class ProviderDriverException extends FreeSwitchEslException
{
    public static function notRegistered(string $providerCode): self
    {
        return new self("No driver registered for provider [{$providerCode}].");
    }

    public static function driverClassNotFound(string $providerCode, string $driverClass): self
    {
        return new self("Driver class [{$driverClass}] for provider [{$providerCode}] does not exist.");
    }

    public static function driverMustImplementInterface(string $providerCode, string $driverClass): self
    {
        return new self(
            "Driver class [{$driverClass}] for provider [{$providerCode}] must implement ProviderDriverInterface."
        );
    }

    public static function commandFailed(string $command, string $reason): self
    {
        return new self("ESL command [{$command}] failed: {$reason}");
    }
}
