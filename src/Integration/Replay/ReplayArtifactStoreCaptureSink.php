<?php

namespace ApnTalk\LaravelFreeswitchEsl\Integration\Replay;

use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\ConnectionContext;
use Apntalk\EslCore\Contracts\ReplayCaptureSinkInterface;
use Apntalk\EslCore\Contracts\ReplayEnvelopeInterface;
use Apntalk\EslReplay\Contracts\ReplayArtifactStoreInterface;
use Psr\Log\LoggerInterface;

final class ReplayArtifactStoreCaptureSink implements ReplayCaptureSinkInterface
{
    public function __construct(
        private readonly ReplayArtifactStoreInterface $store,
        private readonly ConnectionContext $context,
        private readonly LoggerInterface $logger,
    ) {}

    public function capture(ReplayEnvelopeInterface $envelope): void
    {
        try {
            $this->store->write(new ReplayEnvelopeArtifactAdapter($envelope, $this->context));
        } catch (\Throwable $e) {
            $this->logger->warning('Replay artifact capture failed', [
                'provider_code' => $this->context->providerCode,
                'pbx_node_slug' => $this->context->pbxNodeSlug,
                'worker_session_id' => $this->context->workerSessionId,
                'replay_session_id' => $envelope->sessionId(),
                'artifact_name' => $envelope->capturedName(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
