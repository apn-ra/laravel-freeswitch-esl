<?php

namespace ApnTalk\LaravelFreeswitchEsl\Integration\Replay;

use Apntalk\EslCore\Contracts\ReplayCaptureSinkInterface;
use Apntalk\EslReplay\Contracts\ReplayArtifactStoreInterface;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\ConnectionContext;
use Psr\Log\LoggerInterface;

final class ReplayCaptureSinkFactory
{
    public function __construct(
        private readonly ReplayArtifactStoreInterface $store,
        private readonly LoggerInterface $logger,
    ) {}

    public function make(ConnectionContext $context): ReplayCaptureSinkInterface
    {
        return new ReplayArtifactStoreCaptureSink($this->store, $context, $this->logger);
    }
}
