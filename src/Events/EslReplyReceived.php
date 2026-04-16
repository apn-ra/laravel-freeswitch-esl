<?php

namespace ApnTalk\LaravelFreeswitchEsl\Events;

use Apntalk\EslCore\Contracts\ReplyInterface;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\ConnectionContext;

/**
 * Laravel event dispatched when a typed ESL reply is received from a PBX node.
 *
 * This event wraps an apntalk/esl-core ReplyInterface and the ConnectionContext
 * that identifies the source node and worker session.
 *
 * Dispatched by: EslCoreEventBridge::dispatch()
 * Source: apntalk/esl-core InboundPipeline (reply messages only)
 *
 * Note: auth replies (AuthAcceptedReply, ErrorReply after auth) and bgapi
 * acceptance replies (BgapiAcceptedReply) are also dispatched as this event.
 * The runtime layer (esl-react, 0.3.x) handles auth/bgapi correlation
 * internally; application code typically listens only for ApiReply or
 * BgapiAcceptedReply when implementing command-response patterns.
 *
 * Typical reply types:
 *   - AuthAcceptedReply (connection established)
 *   - ApiReply (synchronous API response)
 *   - BgapiAcceptedReply (bgapi job accepted, result arrives via BACKGROUND_JOB event)
 *   - CommandReply (generic command acknowledgement)
 *   - ErrorReply (command rejected)
 *   - UnknownReply (safe degradation for unrecognized replies)
 *
 * Runtime identity is always carried in $context:
 *   - $context->providerCode
 *   - $context->pbxNodeId
 *   - $context->pbxNodeSlug
 *   - $context->workerSessionId
 */
final class EslReplyReceived
{
    public function __construct(
        /**
         * Typed ESL reply from apntalk/esl-core.
         * Access specific reply fields via the concrete type (cast if needed).
         */
        public readonly ReplyInterface $reply,

        /**
         * Connection context — identifies the source node and session.
         * Do not log $context->resolvedPassword; use $context->toLogContext() instead.
         */
        public readonly ConnectionContext $context,
    ) {}
}
