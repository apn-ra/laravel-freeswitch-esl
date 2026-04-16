<?php

namespace ApnTalk\LaravelFreeswitchEsl\Contracts\Upstream;

/**
 * Stub contract representing the ESL client interface expected from apntalk/esl-core.
 *
 * Once apntalk/esl-core is published and required, this stub should be replaced
 * by the canonical interface from that package. Adapters in this package that
 * depend on ESL client behavior should type-hint against the apntalk/esl-core
 * canonical type.
 *
 * @internal This stub exists only for development-phase isolation.
 *           Do not use this as a stable public API surface.
 *           Boundary: apntalk/esl-core owns the real implementation.
 */
interface EslClientInterface
{
    /**
     * Returns true if the client is currently connected.
     */
    public function isConnected(): bool;

    /**
     * Disconnect from the ESL server.
     */
    public function disconnect(): void;
}
