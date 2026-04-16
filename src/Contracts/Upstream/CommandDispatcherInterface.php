<?php

namespace ApnTalk\LaravelFreeswitchEsl\Contracts\Upstream;

/**
 * Stub contract representing the command dispatcher expected from apntalk/esl-core.
 *
 * Once apntalk/esl-core is published and required, concrete adapters in this
 * package should wrap or proxy the real apntalk/esl-core dispatcher.
 *
 * @internal Boundary: apntalk/esl-core owns the canonical implementation.
 */
interface CommandDispatcherInterface
{
    /**
     * Dispatch a synchronous ESL command and return the response body.
     *
     * @throws \ApnTalk\LaravelFreeswitchEsl\Exceptions\ProviderDriverException
     */
    public function dispatch(string $command): string;

    /**
     * Dispatch a bgapi command and return the job UUID.
     *
     * @throws \ApnTalk\LaravelFreeswitchEsl\Exceptions\ProviderDriverException
     */
    public function bgapi(string $command): string;
}
