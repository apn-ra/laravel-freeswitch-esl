<?php

namespace ApnTalk\LaravelFreeswitchEsl\Facades;

use ApnTalk\LaravelFreeswitchEsl\Contracts\ConnectionResolverInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\HealthReporterInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\PbxRegistryInterface;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\ConnectionContext;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\HealthSnapshot;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\PbxNode;

/**
 * Manager class backing the FreeSwitchEsl facade.
 *
 * Aggregates the most commonly needed control-plane operations behind a
 * single injectable service. Not intended to be the only entry point —
 * direct injection of specific interfaces is preferred in service classes.
 */
class FreeSwitchEslManager
{
    public function __construct(
        private readonly PbxRegistryInterface $registry,
        private readonly ConnectionResolverInterface $resolver,
        private readonly HealthReporterInterface $healthReporter,
    ) {}

    public function findNode(string $slug): PbxNode
    {
        return $this->registry->findBySlug($slug);
    }

    /**
     * @return PbxNode[]
     */
    public function allActiveNodes(): array
    {
        return $this->registry->allActive();
    }

    public function resolveConnection(string $slug): ConnectionContext
    {
        return $this->resolver->resolveForSlug($slug);
    }

    /**
     * @return HealthSnapshot[]
     */
    public function health(): array
    {
        return $this->healthReporter->forAllActive();
    }
}
