<?php

namespace ApnTalk\LaravelFreeswitchEsl\ControlPlane\Services;

use ApnTalk\LaravelFreeswitchEsl\Contracts\ProviderDriverInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\ProviderDriverRegistryInterface;
use ApnTalk\LaravelFreeswitchEsl\Exceptions\ProviderDriverException;
use Illuminate\Contracts\Container\Container;

/**
 * Registry for provider driver implementations.
 *
 * At service-provider boot time, each configured driver class is registered
 * under its provider code. At resolution time the container resolves the
 * concrete driver instance, enabling constructor injection in driver classes.
 */
class ProviderDriverRegistry implements ProviderDriverRegistryInterface
{
    /** @var array<string, string> providerCode => driverClass */
    private array $drivers = [];

    /** @var array<string, ProviderDriverInterface> resolved instance cache */
    private array $resolved = [];

    public function __construct(private readonly Container $container) {}

    public function register(string $providerCode, string $driverClass): void
    {
        if (! class_exists($driverClass)) {
            throw ProviderDriverException::driverClassNotFound($providerCode, $driverClass);
        }

        if (! in_array(ProviderDriverInterface::class, class_implements($driverClass) ?: [], true)) {
            throw ProviderDriverException::driverMustImplementInterface($providerCode, $driverClass);
        }

        $this->drivers[$providerCode] = $driverClass;
    }

    public function resolve(string $providerCode): ProviderDriverInterface
    {
        if (isset($this->resolved[$providerCode])) {
            return $this->resolved[$providerCode];
        }

        if (! isset($this->drivers[$providerCode])) {
            throw ProviderDriverException::notRegistered($providerCode);
        }

        $driver = $this->container->make($this->drivers[$providerCode]);

        $this->resolved[$providerCode] = $driver;

        return $driver;
    }

    public function has(string $providerCode): bool
    {
        return isset($this->drivers[$providerCode]);
    }

    public function registeredCodes(): array
    {
        return array_keys($this->drivers);
    }
}
