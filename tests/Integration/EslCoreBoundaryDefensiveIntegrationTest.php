<?php

namespace ApnTalk\LaravelFreeswitchEsl\Tests\Integration;

use Apntalk\EslCore\Exceptions\TruncatedFrameException;
use Apntalk\EslCore\Inbound\DecodedInboundMessage;
use Apntalk\EslCore\Inbound\InboundMessageType;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\ConnectionContext;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslCoreEventBridge;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslCorePipelineFactory;
use ApnTalk\LaravelFreeswitchEsl\Tests\TestCase;

class EslCoreBoundaryDefensiveIntegrationTest extends TestCase
{
    public function test_container_bound_bridge_ignores_malformed_decoded_event_without_dispatching_laravel_events(): void
    {
        $received = false;
        $this->app['events']->listen('*', function () use (&$received) {
            $received = true;
        });

        /** @var EslCoreEventBridge $bridge */
        $bridge = $this->app->make(EslCoreEventBridge::class);
        $bridge->dispatch($this->makeMalformedMessage(InboundMessageType::Event), $this->makeContext());

        $this->assertFalse($received);
    }

    public function test_container_bound_pipeline_fails_closed_on_truncated_frame_without_dispatching_laravel_events(): void
    {
        $received = false;
        $this->app['events']->listen('*', function () use (&$received) {
            $received = true;
        });

        /** @var EslCorePipelineFactory $factory */
        $factory = $this->app->make(EslCorePipelineFactory::class);
        $pipeline = $factory->createPipeline();

        $pipeline->push("Content-Type: text/event-plain\nContent-Length: 5\n\nab");

        $messages = $pipeline->drain();

        $this->assertSame([], $messages);

        try {
            $pipeline->finish();
            $this->fail('Expected a truncated-frame failure for incomplete inbound bytes.');
        } catch (TruncatedFrameException) {
            $this->assertFalse($received);
        }
    }

    private function makeContext(): ConnectionContext
    {
        return new ConnectionContext(
            pbxNodeId: 1,
            pbxNodeSlug: 'test-node',
            providerCode: 'freeswitch',
            host: '10.0.0.1',
            port: 8021,
            username: '',
            resolvedPassword: 'ClueCon',
            transport: 'tcp',
            connectionProfileId: null,
            connectionProfileName: 'default',
        );
    }

    private function makeMalformedMessage(InboundMessageType $type): DecodedInboundMessage
    {
        $reflection = new \ReflectionClass(DecodedInboundMessage::class);
        $constructor = $reflection->getConstructor();
        \assert($constructor instanceof \ReflectionMethod);
        $constructor->setAccessible(true);

        /** @var DecodedInboundMessage $message */
        $message = $reflection->newInstanceWithoutConstructor();
        $constructor->invoke($message, $type, null, null, null);

        return $message;
    }
}
