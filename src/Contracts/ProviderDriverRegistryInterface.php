<?php

namespace ApnTalk\LaravelFreeswitchEsl\Contracts;

/**
 * Resolves registered provider driver implementations by provider code.
 *
 * The registry maps provider codes (e.g. 'freeswitch') to their driver class
 * implementations. Driver classes are defined in config and registered at
 * service-provider boot time.
 */
interface ProviderDriverRegistryInterface
{
    /**
     * Register a driver for the given provider code.
     */
    public function register(string $providerCode, string $driverClass): void;

    /**
     * Resolve the driver instance for the given provider code.
     *
     * @throws \ApnTalk\LaravelFreeswitchEsl\Exceptions\ProviderDriverException
     */
    public function resolve(string $providerCode): ProviderDriverInterface;

    /**
     * Returns true if a driver is registered for the given provider code.
     */
    public function has(string $providerCode): bool;

    /**
     * Return all registered provider codes.
     *
     * @return string[]
     */
    public function registeredCodes(): array;
}
