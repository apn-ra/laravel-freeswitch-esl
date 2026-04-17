<?php

namespace ApnTalk\LaravelFreeswitchEsl\Integration;

use Apntalk\EslCore\Contracts\CommandInterface;
use Apntalk\EslCore\Contracts\InboundPipelineInterface;
use Apntalk\EslCore\Contracts\TransportInterface;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\ConnectionContext;
use ApnTalk\LaravelFreeswitchEsl\Contracts\RuntimeHandoffInterface;

/**
 * Package-owned connection handle for the current esl-core integration seam.
 *
 * This handle does not own reconnects, supervision, or event loops. It simply
 * packages the resolved Laravel control-plane context together with the esl-core
 * protocol primitives runtime adapters consume.
 */
final class EslCoreConnectionHandle implements RuntimeHandoffInterface
{
    /** @var list<CommandInterface> */
    private readonly array $openingSequence;

    /** @var list<CommandInterface> */
    private readonly array $closingSequence;

    private ?TransportInterface $transport = null;

    /**
     * @param  list<CommandInterface>  $openingSequence
     * @param  list<CommandInterface>  $closingSequence
     * @param  \Closure(ConnectionContext): TransportInterface  $transportOpener
     */
    public function __construct(
        public readonly ConnectionContext $context,
        private readonly InboundPipelineInterface $pipeline,
        array $openingSequence,
        array $closingSequence,
        private readonly \Closure $transportOpener,
    ) {
        $this->openingSequence = $openingSequence;
        $this->closingSequence = $closingSequence;
    }

    public function context(): ConnectionContext
    {
        return $this->context;
    }

    public function pipeline(): InboundPipelineInterface
    {
        return $this->pipeline;
    }

    /**
     * @return list<CommandInterface>
     */
    public function openingSequence(): array
    {
        return $this->openingSequence;
    }

    /**
     * @return list<CommandInterface>
     */
    public function closingSequence(): array
    {
        return $this->closingSequence;
    }

    /**
     * Open or return the raw transport for this handle.
     *
     * The transport is created lazily so this handle can be assembled and tested
     * without forcing an immediate network dial.
     */
    public function openTransport(): TransportInterface
    {
        if ($this->transport !== null) {
            return $this->transport;
        }

        $this->transport = ($this->transportOpener)($this->context);

        return $this->transport;
    }

    public function hasOpenTransport(): bool
    {
        return $this->transport !== null && $this->transport->isConnected();
    }

    public function closeTransport(): void
    {
        if ($this->transport === null) {
            return;
        }

        $this->transport->close();
        $this->transport = null;
    }

    public function endpoint(): string
    {
        return sprintf('%s://%s:%d', $this->context->transport, $this->context->host, $this->context->port);
    }
}
