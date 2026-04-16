<?php

namespace ApnTalk\LaravelFreeswitchEsl\Contracts\Upstream;

/**
 * Stub contract representing the ESL event stream expected from apntalk/esl-core.
 *
 * @internal Boundary: apntalk/esl-core owns the canonical implementation.
 */
interface EventStreamInterface
{
    /**
     * Subscribe to an ESL event type by name.
     */
    public function subscribe(string $eventName): void;

    /**
     * Unsubscribe from an ESL event type.
     */
    public function unsubscribe(string $eventName): void;

    /**
     * Register a callback for incoming events.
     * The callback receives a raw event representation.
     */
    public function onEvent(callable $callback): void;
}
