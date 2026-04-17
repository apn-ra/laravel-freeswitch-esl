<?php

namespace ApnTalk\LaravelFreeswitchEsl\Contracts;

use Apntalk\EslCore\Contracts\CommandInterface;
use Apntalk\EslCore\Contracts\InboundPipelineInterface;
use Apntalk\EslCore\Contracts\TransportInterface;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\ConnectionContext;

/**
 * Adapter-facing prepared runtime bundle owned by this Laravel package.
 *
 * This contract represents the handoff state that runtime adapters can consume
 * without re-running control-plane resolution. It is prepared by this package,
 * but it does not itself own a live async runtime loop.
 */
interface RuntimeHandoffInterface
{
    public function context(): ConnectionContext;

    public function pipeline(): InboundPipelineInterface;

    /**
     * @return list<CommandInterface>
     */
    public function openingSequence(): array;

    /**
     * @return list<CommandInterface>
     */
    public function closingSequence(): array;

    public function endpoint(): string;

    public function openTransport(): TransportInterface;

    public function hasOpenTransport(): bool;

    public function closeTransport(): void;
}
