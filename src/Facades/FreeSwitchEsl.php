<?php

namespace ApnTalk\LaravelFreeswitchEsl\Facades;

use ApnTalk\LaravelFreeswitchEsl\Contracts\ConnectionResolverInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\HealthReporterInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\PbxRegistryInterface;
use Illuminate\Support\Facades\Facade;

/**
 * Optional facade providing ergonomic access to commonly used control-plane services.
 *
 * The facade does not replace explicit constructor injection — it is a
 * convenience surface for quick access in service layers or Artisan-level code.
 *
 * Prefer injecting the specific interface directly in production service classes.
 *
 * @method static \ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\PbxNode findNode(string $slug)
 * @method static \ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\PbxNode[] allActiveNodes()
 * @method static \ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\ConnectionContext resolveConnection(string $slug)
 * @method static \ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\HealthSnapshot[] health()
 */
class FreeSwitchEsl extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return FreeSwitchEslManager::class;
    }
}
