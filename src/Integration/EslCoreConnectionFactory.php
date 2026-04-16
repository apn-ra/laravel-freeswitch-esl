<?php

namespace ApnTalk\LaravelFreeswitchEsl\Integration;

use Apntalk\EslCore\Commands\EventFormat;
use Apntalk\EslCore\Contracts\TransportInterface;
use Apntalk\EslCore\Exceptions\TransportException;
use Apntalk\EslCore\Internal\Transport\StreamSocketTransport;
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
    /**
     * @param  \Closure(ConnectionContext): TransportInterface|null  $transportOpener
     */
    public function __construct(
        private readonly EslCoreCommandFactory $commandFactory,
        private readonly EslCorePipelineFactory $pipelineFactory,
        private readonly ?\Closure $transportOpener = null,
    ) {}

    public function create(ConnectionContext $context): EslCoreConnectionHandle
    {
        [$eventNames, $format] = $this->subscriptionSettings($context);

        return new EslCoreConnectionHandle(
            context: $context,
            pipeline: $this->pipelineFactory->createPipeline(),
            openingSequence: $this->commandFactory->buildOpeningSequence($context, $eventNames, $format),
            closingSequence: $this->commandFactory->buildClosingSequence(),
            transportOpener: $this->transportOpener ?? fn (ConnectionContext $ctx): TransportInterface => $this->openTransport($ctx),
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
        $socketUri = $this->socketUri($context);
        $timeoutSeconds = $this->connectTimeoutSeconds($context);

        $stream = @stream_socket_client(
            $socketUri,
            $errorCode,
            $errorMessage,
            $timeoutSeconds,
            STREAM_CLIENT_CONNECT
        );

        if (! is_resource($stream)) {
            throw new TransportException(sprintf(
                'Failed to connect to [%s]: %s (%d)',
                $socketUri,
                $errorMessage ?: 'unknown error',
                (int) $errorCode,
            ));
        }

        stream_set_blocking($stream, false);

        return new StreamSocketTransport($stream);
    }

    private function socketUri(ConnectionContext $context): string
    {
        $scheme = match ($context->transport) {
            'tcp' => 'tcp',
            'tls' => 'tls',
            default => throw new TransportException(
                sprintf('Unsupported transport [%s] for PBX node [%s].', $context->transport, $context->pbxNodeSlug)
            ),
        };

        return sprintf('%s://%s:%d', $scheme, $context->host, $context->port);
    }

    private function connectTimeoutSeconds(ConnectionContext $context): float
    {
        $timeout = $context->driverParameters['connect_timeout_seconds'] ?? 10.0;

        return max(0.1, (float) $timeout);
    }
}
