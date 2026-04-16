<?php

namespace ApnTalk\LaravelFreeswitchEsl\Tests\Unit\Integration;

use Apntalk\EslCore\Contracts\InboundPipelineInterface;
use Apntalk\EslCore\Inbound\DecodedInboundMessage;
use Apntalk\EslCore\Inbound\InboundPipeline;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslCorePipelineFactory;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for EslCorePipelineFactory.
 *
 * Verifies that the factory creates correctly-typed InboundPipeline instances
 * and that each pipeline can decode basic ESL protocol bytes (using a known
 * auth/request fixture from the esl-core protocol).
 *
 * Live PBX not required — all decoding uses synthetic byte fixtures.
 */
class EslCorePipelineFactoryTest extends TestCase
{
    private EslCorePipelineFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new EslCorePipelineFactory();
    }

    public function test_create_pipeline_returns_inbound_pipeline_interface(): void
    {
        $pipeline = $this->factory->createPipeline();

        $this->assertInstanceOf(InboundPipelineInterface::class, $pipeline);
    }

    public function test_create_pipeline_returns_concrete_inbound_pipeline(): void
    {
        $pipeline = $this->factory->createPipeline();

        $this->assertInstanceOf(InboundPipeline::class, $pipeline);
    }

    public function test_create_pipeline_returns_fresh_instance_each_call(): void
    {
        $a = $this->factory->createPipeline();
        $b = $this->factory->createPipeline();

        $this->assertNotSame($a, $b);
    }

    public function test_pipeline_initial_buffered_byte_count_is_zero(): void
    {
        $pipeline = $this->factory->createPipeline();

        $this->assertSame(0, $pipeline->bufferedByteCount());
    }

    public function test_pipeline_drain_returns_empty_list_initially(): void
    {
        $pipeline = $this->factory->createPipeline();

        $this->assertSame([], $pipeline->drain());
    }

    public function test_pipeline_can_decode_auth_request_frame(): void
    {
        $pipeline = $this->factory->createPipeline();

        // Minimal FreeSWITCH auth/request frame
        $frame = "Content-Type: auth/request\n\n";
        $messages = $pipeline->decode($frame);

        $this->assertCount(1, $messages);
        $this->assertInstanceOf(DecodedInboundMessage::class, $messages[0]);
        $this->assertTrue($messages[0]->isServerAuthRequest());
    }

    public function test_pipeline_can_decode_command_reply_frame(): void
    {
        $pipeline = $this->factory->createPipeline();

        // Minimal command/reply frame (auth accepted)
        $frame = "Content-Type: command/reply\nReply-Text: +OK accepted\n\n";
        $messages = $pipeline->decode($frame);

        $this->assertCount(1, $messages);
        $this->assertTrue($messages[0]->isReply());
    }

    public function test_pipeline_can_decode_event_plain_frame(): void
    {
        $pipeline = $this->factory->createPipeline();

        // Minimal text/event-plain frame
        $body = "Event-Name: HEARTBEAT\nCore-UUID: test-uuid\nEvent-Date-Timestamp: 1000000\n";
        $frame = sprintf(
            "Content-Type: text/event-plain\nContent-Length: %d\n\n%s",
            strlen($body),
            $body
        );

        $messages = $pipeline->decode($frame);

        $this->assertCount(1, $messages);
        $this->assertTrue($messages[0]->isEvent());
        $this->assertNotNull($messages[0]->event());
        $this->assertNotNull($messages[0]->normalizedEvent());
    }

    public function test_pipeline_can_be_reset(): void
    {
        $pipeline = $this->factory->createPipeline();

        // Push a partial frame
        $pipeline->push("Content-Type: auth/request");
        $this->assertGreaterThan(0, $pipeline->bufferedByteCount());

        // Reset clears the buffer
        $pipeline->reset();
        $this->assertSame(0, $pipeline->bufferedByteCount());
        $this->assertSame([], $pipeline->drain());
    }

    public function test_pipeline_instances_are_independent(): void
    {
        $pipelineA = $this->factory->createPipeline();
        $pipelineB = $this->factory->createPipeline();

        // Push bytes into A only
        $pipelineA->push("Content-Type: auth/request\n\n");

        // B should not see A's bytes
        $this->assertSame([], $pipelineB->drain());
        $this->assertCount(1, $pipelineA->drain());
    }
}
