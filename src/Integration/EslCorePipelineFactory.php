<?php

namespace ApnTalk\LaravelFreeswitchEsl\Integration;

use Apntalk\EslCore\Contracts\InboundPipelineInterface;
use Apntalk\EslCore\Inbound\InboundPipeline;

/**
 * Creates apntalk/esl-core InboundPipeline instances for ESL byte-stream decoding.
 *
 * The pipeline is the stable public facade for converting raw inbound bytes
 * into typed DecodedInboundMessage objects (events, replies, auth requests,
 * disconnect notices).
 *
 * Ownership model:
 *   - This factory is owned by this Laravel package.
 *   - The InboundPipeline implementation is owned by apntalk/esl-core.
 *   - The byte source (transport read loop) is owned by apntalk/esl-react.
 *
 * Runtime adapters call createPipeline() to obtain a fresh pipeline per worker
 * session, then feed raw bytes from the transport into it via push().
 *
 * Preferred upstream construction path:
 *   InboundPipeline::withDefaults()
 *
 * Boundary: do NOT add transport I/O, reconnect logic, or session state here.
 */
final class EslCorePipelineFactory
{
    /**
     * Create a fresh InboundPipeline for one ESL worker session.
     *
     * Each worker session should use its own pipeline instance.
     * Pipelines are stateful (they buffer partial frames) and must
     * not be shared across sessions.
     */
    public function createPipeline(): InboundPipelineInterface
    {
        return InboundPipeline::withDefaults();
    }
}
