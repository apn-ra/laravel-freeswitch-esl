<?php

namespace ApnTalk\LaravelFreeswitchEsl\Integration;

use Apntalk\EslCore\Commands\EventFormat;
use Apntalk\EslCore\Contracts\TransportFactoryInterface;
use Apntalk\EslCore\Contracts\TransportInterface;
use Apntalk\EslCore\Exceptions\TransportException;
use Apntalk\EslCore\Transport\SocketEndpoint;
use ApnTalk\LaravelFreeswitchEsl\Contracts\ConnectionFactoryInterface;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\ConnectionContext;

/**
 * Assembles the current esl-core connection seam from a resolved context.
 *
 * This factory is intentionally narrow:
 *   - it creates a package-owned connection handle
 *   - it prepares the auth/subscription command sequence
 *   - it creates a fresh inbound pipeline
 *   - it provides lazy transport opening for bounded call sites
 *
 * It does not own reconnects, worker loops, or runtime supervision.
 */
final class EslCoreConnectionFactory implements ConnectionFactoryInterface
{
    public function __construct(
        private readonly EslCoreCommandFactory $commandFactory,
        private readonly EslCorePipelineFactory $pipelineFactory,
        private readonly TransportFactoryInterface $transportFactory,
    ) {}

    public function create(ConnectionContext $context): EslCoreConnectionHandle
    {
        [$eventNames, $format] = $this->subscriptionSettings($context);

        return new EslCoreConnectionHandle(
            context: $context,
            pipeline: $this->pipelineFactory->createPipeline(),
            openingSequence: $this->commandFactory->buildOpeningSequence($context, $eventNames, $format),
            closingSequence: $this->commandFactory->buildClosingSequence(),
            transportOpener: fn (ConnectionContext $ctx) => $this->openTransport($ctx),
        );
    }

    /**
     * @return array{0: list<string>, 1: EventFormat}
     */
    private function subscriptionSettings(ConnectionContext $context): array
    {
        $subscription = $context->driverParameters['subscription'] ?? [];

        if (! is_array($subscription)) {
            return [[], EventFormat::Plain];
        }

        $eventNames = $subscription['event_names'] ?? [];
        $eventNames = is_array($eventNames)
            ? array_values(array_filter($eventNames, static fn (mixed $name): bool => is_string($name) && $name !== ''))
            : [];

        $format = match ($subscription['format'] ?? 'plain') {
            'json' => EventFormat::Json,
            'xml' => EventFormat::Xml,
            default => EventFormat::Plain,
        };

        return [$eventNames, $format];
    }

    private function openTransport(ConnectionContext $context): TransportInterface
    {
        return $this->transportFactory->connect($this->socketEndpoint($context));
    }

    private function socketEndpoint(ConnectionContext $context): SocketEndpoint
    {
        $timeoutSeconds = $this->connectTimeoutSeconds($context);
        $contextOptions = $this->streamContextOptions($context);

        return match ($context->transport) {
            'tcp' => SocketEndpoint::tcp($context->host, $context->port, $timeoutSeconds, $contextOptions),
            'tls' => new SocketEndpoint(
                sprintf('tls://%s:%d', $context->host, $context->port),
                $timeoutSeconds,
                $contextOptions,
            ),
            default => throw new TransportException(
                sprintf('Unsupported transport [%s] for PBX node [%s].', $context->transport, $context->pbxNodeSlug)
            ),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function streamContextOptions(ConnectionContext $context): array
    {
        $options = $context->driverParameters['stream_context_options'] ?? [];

        return is_array($options) ? $options : [];
    }

    private function connectTimeoutSeconds(ConnectionContext $context): float
    {
        $timeout = $context->driverParameters['connect_timeout_seconds'] ?? 10.0;

        return max(0.1, (float) $timeout);
    }
}
