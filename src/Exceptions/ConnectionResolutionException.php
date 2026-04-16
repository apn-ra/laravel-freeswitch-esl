<?php

namespace ApnTalk\LaravelFreeswitchEsl\Exceptions;

class ConnectionResolutionException extends FreeSwitchEslException
{
    public static function unknownSecretMode(string $mode): self
    {
        return new self("Unknown secret resolution mode [{$mode}]. Supported: plaintext, env, custom.");
    }

    public static function envSecretNotFound(string $envKey): self
    {
        return new self("Environment variable [{$envKey}] not found for secret resolution.");
    }

    public static function customResolverNotConfigured(): self
    {
        return new self('Secret mode is set to [custom] but no custom resolver class is configured.');
    }

    public static function driverBuildFailed(string $providerCode, string $reason): self
    {
        return new self("Connection context build failed for provider [{$providerCode}]: {$reason}");
    }
}
