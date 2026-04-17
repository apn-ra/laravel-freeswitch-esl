<?php

namespace ApnTalk\LaravelFreeswitchEsl\Events;

use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\ConnectionContext;

/**
 * Laravel event dispatched when a FreeSWITCH ESL disconnect notice is received.
 *
 * FreeSWITCH sends a disconnect/notify frame before closing the connection
 * (e.g., during a planned shutdown or reload). This event is dispatched when
 * the inbound pipeline decodes such a frame.
 *
 * Dispatched by: EslCoreEventBridge::dispatch()
 * Source: apntalk/esl-core InboundPipeline (DisconnectNotice messages only)
 *
 * When the runtime (apntalk/esl-react) is wired (0.3.x), it will handle the
 * actual reconnect lifecycle. This event is for application-layer awareness:
 * listeners may use it to log, alert, or update their own state. The runtime
 * layer is not obligated to stop reconnecting because this event was dispatched.
 *
 * Runtime identity is always carried in $context:
 *   - $context->providerCode
 *   - $context->pbxNodeId
 *   - $context->pbxNodeSlug
 *   - $context->workerSessionId
 */
final class EslDisconnected
{
    public const SCHEMA_VERSION = '1.0';

    public function __construct(
        /**
         * Connection context at the time of the disconnect notice.
         * Do not log $context->resolvedPassword; use $context->toLogContext() instead.
         */
        public readonly ConnectionContext $context,
        /**
         * Laravel event-bridge schema version for downstream consumers.
         */
        public readonly string $schemaVersion = self::SCHEMA_VERSION,
    ) {}
}
