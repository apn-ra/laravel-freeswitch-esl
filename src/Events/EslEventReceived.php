<?php

namespace ApnTalk\LaravelFreeswitchEsl\Events;

use Apntalk\EslCore\Contracts\EventInterface;
use Apntalk\EslCore\Events\NormalizedEvent;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\ConnectionContext;

/**
 * Laravel event dispatched when a typed ESL event is received from a PBX node.
 *
 * This event wraps an apntalk/esl-core EventInterface (the typed event) and
 * the NormalizedEvent substrate (raw decoded headers), together with the
 * ConnectionContext that identifies the source PBX node and worker session.
 *
 * Dispatched by: EslCoreEventBridge::dispatch()
 * Source: apntalk/esl-core InboundPipeline (event messages only)
 *
 * Typical event types:
 *   - ChannelLifecycleEvent (CHANNEL_CREATE, CHANNEL_ANSWER, etc.)
 *   - HangupEvent (CHANNEL_HANGUP, CHANNEL_HANGUP_COMPLETE)
 *   - BridgeEvent (CHANNEL_BRIDGE, CHANNEL_UNBRIDGE)
 *   - BackgroundJobEvent (BACKGROUND_JOB)
 *   - PlaybackEvent (PLAYBACK_START, PLAYBACK_STOP)
 *   - CustomEvent (CUSTOM)
 *   - RawEvent (all unrecognized event types — safe degradation)
 *
 * Runtime identity is always carried in $context:
 *   - $context->providerCode
 *   - $context->pbxNodeId
 *   - $context->pbxNodeSlug
 *   - $context->workerSessionId
 */
final class EslEventReceived
{
    public function __construct(
        /**
         * Typed ESL event from apntalk/esl-core.
         * Access specific event fields via the concrete type (cast if needed).
         */
        public readonly EventInterface $event,

        /**
         * Normalized event substrate — decoded headers and raw body.
         * Available for any header not covered by the typed event class.
         */
        public readonly NormalizedEvent $normalizedEvent,

        /**
         * Connection context — identifies the source node and session.
         * Do not log $context->resolvedPassword; use $context->toLogContext() instead.
         */
        public readonly ConnectionContext $context,
    ) {}
}
