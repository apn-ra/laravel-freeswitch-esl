<?php

namespace ApnTalk\LaravelFreeswitchEsl\Integration;

use Apntalk\EslCore\Contracts\ReplayCaptureSinkInterface;
use Apntalk\EslReact\Config\RuntimeConfig;
use Apntalk\EslReact\Config\SubscriptionConfig;
use Apntalk\EslReact\Runner\PreparedRuntimeBootstrapInput;
use Apntalk\EslReact\Runner\RuntimeSessionContext;
use ApnTalk\LaravelFreeswitchEsl\Contracts\RuntimeHandoffInterface;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\ConnectionContext;
use ApnTalk\LaravelFreeswitchEsl\Integration\Replay\ReplayCaptureSinkFactory;
use React\Socket\Connector;
use React\Socket\ConnectorInterface;

/**
 * Maps Laravel-owned runtime handoffs into the prepared esl-react input seam.
 *
 * This class deliberately does not open the esl-core TransportInterface held by
 * the handoff. Direct transport polling handoff remains deferred until
 * apntalk/esl-react exposes that public path.
 */
final class EslReactRuntimeBootstrapInputFactory
{
    /**
     * @param  array<string, mixed>  $connectorOptions
     */
    public function __construct(
        private readonly array $connectorOptions = [],
        private readonly ?ConnectorInterface $connector = null,
        private readonly ?ReplayCaptureSinkFactory $replayCaptureSinkFactory = null,
        private readonly bool $replayCaptureEnabled = false,
    ) {}

    public function create(RuntimeHandoffInterface $handoff): PreparedRuntimeBootstrapInput
    {
        $context = $handoff->context();

        return new PreparedRuntimeBootstrapInput(
            endpoint: $handoff->endpoint(),
            runtimeConfig: $this->runtimeConfig($context),
            connector: $this->connector ?? new Connector($this->connectorOptions),
            inboundPipeline: $handoff->pipeline(),
            sessionContext: $this->sessionContext($context),
            dialUri: $this->explicitDialUri($context),
        );
    }

    private function runtimeConfig(ConnectionContext $context): RuntimeConfig
    {
        [$replayCaptureEnabled, $replayCaptureSinks] = $this->replayCaptureSettings($context);

        return RuntimeConfig::create(
            host: $context->host,
            port: $context->port,
            password: $context->resolvedPassword,
            subscriptions: $this->subscriptionConfig($context),
            replayCaptureEnabled: $replayCaptureEnabled,
            replayCaptureSinks: $replayCaptureSinks,
        );
    }

    /**
     * @return array{0: bool, 1: list<ReplayCaptureSinkInterface>}
     */
    private function replayCaptureSettings(ConnectionContext $context): array
    {
        if (! $this->replayCaptureEnabled || $this->replayCaptureSinkFactory === null) {
            return [false, []];
        }

        return [true, [$this->replayCaptureSinkFactory->make($context)]];
    }

    private function subscriptionConfig(ConnectionContext $context): SubscriptionConfig
    {
        $subscription = $context->driverParameters['subscription'] ?? [];

        if (! is_array($subscription)) {
            return SubscriptionConfig::all();
        }

        $eventNames = $subscription['event_names'] ?? [];

        if (! is_array($eventNames) || $eventNames === []) {
            return SubscriptionConfig::all();
        }

        $normalized = [];

        foreach ($eventNames as $eventName) {
            if (is_string($eventName) && $eventName !== '') {
                $normalized[] = $eventName;
            }
        }

        return $normalized === []
            ? SubscriptionConfig::all()
            : SubscriptionConfig::forEvents(...$normalized);
    }

    private function sessionContext(ConnectionContext $context): RuntimeSessionContext
    {
        $sessionId = $context->workerSessionId;

        if ($sessionId === null || $sessionId === '') {
            $sessionId = sprintf('pbx-%s', $context->pbxNodeSlug);
        }

        return new RuntimeSessionContext($sessionId, [
            'provider_code' => $context->providerCode,
            'pbx_node_id' => $context->pbxNodeId,
            'pbx_node_slug' => $context->pbxNodeSlug,
            'connection_profile_id' => $context->connectionProfileId,
            'connection_profile_name' => $context->connectionProfileName,
            'worker_session_id' => $context->workerSessionId,
            'transport' => $context->transport,
        ]);
    }

    private function explicitDialUri(ConnectionContext $context): ?string
    {
        return match ($context->transport) {
            'tcp' => null,
            'tls' => sprintf('tls://%s:%d', $context->host, $context->port),
            default => throw new \InvalidArgumentException(sprintf(
                'apntalk/esl-react prepared bootstrap does not support explicit dial URI mapping for transport [%s] on PBX node [%s].',
                $context->transport,
                $context->pbxNodeSlug,
            )),
        };
    }
}
