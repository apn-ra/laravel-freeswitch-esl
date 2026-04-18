<?php

namespace ApnTalk\LaravelFreeswitchEsl\Tests\Unit\Integration;

use Apntalk\EslCore\Inbound\DecodedInboundMessage;
use Apntalk\EslCore\Inbound\InboundMessageType;
use Apntalk\EslCore\Inbound\InboundPipeline;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\ConnectionContext;
use ApnTalk\LaravelFreeswitchEsl\Events\EslDisconnected;
use ApnTalk\LaravelFreeswitchEsl\Events\EslEventReceived;
use ApnTalk\LaravelFreeswitchEsl\Events\EslReplyReceived;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslCoreEventBridge;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for EslCoreEventBridge.
 *
 * Verifies that the bridge correctly maps decoded esl-core messages to the
 * corresponding Laravel event classes and that the ConnectionContext is
 * propagated on each dispatch.
 *
 * Uses a real Illuminate Dispatcher (not a mock) to capture dispatched events,
 * and a real InboundPipeline to decode protocol fixtures into typed messages.
 *
 * Live PBX not required — all decoding uses synthetic byte fixtures.
 */
class EslCoreEventBridgeTest extends TestCase
{
    private Dispatcher $laravelEvents;

    private EslCoreEventBridge $bridge;

    private ConnectionContext $context;

    protected function setUp(): void
    {
        $this->laravelEvents = new Dispatcher;
        $this->bridge = new EslCoreEventBridge($this->laravelEvents);
        $this->context = $this->makeContext();
    }

    public function test_event_message_dispatches_esl_event_received(): void
    {
        $dispatched = [];
        $this->laravelEvents->listen(EslEventReceived::class, function (EslEventReceived $e) use (&$dispatched) {
            $dispatched[] = $e;
        });

        $message = $this->decodeEventFrame();
        $this->bridge->dispatch($message, $this->context);

        $this->assertCount(1, $dispatched);
        $this->assertInstanceOf(EslEventReceived::class, $dispatched[0]);
    }

    public function test_event_message_carries_context(): void
    {
        $dispatched = [];
        $this->laravelEvents->listen(EslEventReceived::class, function (EslEventReceived $e) use (&$dispatched) {
            $dispatched[] = $e;
        });

        $message = $this->decodeEventFrame();
        $this->bridge->dispatch($message, $this->context);

        $this->assertSame($this->context, $dispatched[0]->context);
        $this->assertSame('test-node', $dispatched[0]->context->pbxNodeSlug);
    }

    public function test_event_message_carries_typed_event_and_normalized_event(): void
    {
        $dispatched = [];
        $this->laravelEvents->listen(EslEventReceived::class, function (EslEventReceived $e) use (&$dispatched) {
            $dispatched[] = $e;
        });

        $message = $this->decodeEventFrame();
        $this->bridge->dispatch($message, $this->context);

        $this->assertNotNull($dispatched[0]->event);
        $this->assertNotNull($dispatched[0]->normalizedEvent);
        $this->assertSame('HEARTBEAT', $dispatched[0]->event->eventName());
        $this->assertSame(EslEventReceived::SCHEMA_VERSION, $dispatched[0]->schemaVersion);
    }

    public function test_reply_message_dispatches_esl_reply_received(): void
    {
        $dispatched = [];
        $this->laravelEvents->listen(EslReplyReceived::class, function (EslReplyReceived $e) use (&$dispatched) {
            $dispatched[] = $e;
        });

        $message = $this->decodeReplyFrame();
        $this->bridge->dispatch($message, $this->context);

        $this->assertCount(1, $dispatched);
        $this->assertInstanceOf(EslReplyReceived::class, $dispatched[0]);
    }

    public function test_reply_message_carries_context(): void
    {
        $dispatched = [];
        $this->laravelEvents->listen(EslReplyReceived::class, function (EslReplyReceived $e) use (&$dispatched) {
            $dispatched[] = $e;
        });

        $message = $this->decodeReplyFrame();
        $this->bridge->dispatch($message, $this->context);

        $this->assertSame($this->context, $dispatched[0]->context);
    }

    public function test_reply_message_carries_typed_reply(): void
    {
        $dispatched = [];
        $this->laravelEvents->listen(EslReplyReceived::class, function (EslReplyReceived $e) use (&$dispatched) {
            $dispatched[] = $e;
        });

        $message = $this->decodeReplyFrame();
        $this->bridge->dispatch($message, $this->context);

        $this->assertNotNull($dispatched[0]->reply);
        $this->assertTrue($dispatched[0]->reply->isSuccess());
        $this->assertSame(EslReplyReceived::SCHEMA_VERSION, $dispatched[0]->schemaVersion);
    }

    public function test_disconnect_notice_dispatches_esl_disconnected(): void
    {
        $dispatched = [];
        $this->laravelEvents->listen(EslDisconnected::class, function (EslDisconnected $e) use (&$dispatched) {
            $dispatched[] = $e;
        });

        $message = DecodedInboundMessage::forDisconnectNotice();
        $this->bridge->dispatch($message, $this->context);

        $this->assertCount(1, $dispatched);
        $this->assertSame($this->context, $dispatched[0]->context);
        $this->assertSame(EslDisconnected::SCHEMA_VERSION, $dispatched[0]->schemaVersion);
    }

    public function test_auth_request_is_not_dispatched(): void
    {
        $received = false;
        $this->laravelEvents->listen('*', function () use (&$received) {
            $received = true;
        });

        $message = DecodedInboundMessage::forServerAuthRequest();
        $this->bridge->dispatch($message, $this->context);

        $this->assertFalse($received, 'Auth requests must not be dispatched as Laravel events');
    }

    public function test_dispatch_all_processes_all_messages(): void
    {
        $eventCount = 0;
        $replyCount = 0;

        $this->laravelEvents->listen(EslEventReceived::class, function () use (&$eventCount) {
            $eventCount++;
        });
        $this->laravelEvents->listen(EslReplyReceived::class, function () use (&$replyCount) {
            $replyCount++;
        });

        $messages = [
            $this->decodeEventFrame(),
            $this->decodeReplyFrame(),
            $this->decodeEventFrame(),
        ];

        $this->bridge->dispatchAll($messages, $this->context);

        $this->assertSame(2, $eventCount);
        $this->assertSame(1, $replyCount);
    }

    public function test_dispatch_all_with_empty_list_dispatches_nothing(): void
    {
        $received = false;
        $this->laravelEvents->listen('*', function () use (&$received) {
            $received = true;
        });

        $this->bridge->dispatchAll([], $this->context);

        $this->assertFalse($received);
    }

    public function test_event_message_with_missing_typed_or_normalized_payload_is_ignored_without_throwing(): void
    {
        $received = false;
        $this->laravelEvents->listen('*', function () use (&$received) {
            $received = true;
        });

        $this->bridge->dispatch($this->makeMalformedMessage(InboundMessageType::Event), $this->context);

        $this->assertFalse($received);
    }

    public function test_reply_message_with_missing_typed_reply_is_ignored_without_throwing(): void
    {
        $received = false;
        $this->laravelEvents->listen('*', function () use (&$received) {
            $received = true;
        });

        $this->bridge->dispatch($this->makeMalformedMessage(InboundMessageType::Reply), $this->context);

        $this->assertFalse($received);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

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

    private function decodeEventFrame(): DecodedInboundMessage
    {
        $pipeline = InboundPipeline::withDefaults();
        $body = "Event-Name: HEARTBEAT\nCore-UUID: test-uuid\nEvent-Date-Timestamp: 1000000\n";
        $frame = sprintf(
            "Content-Type: text/event-plain\nContent-Length: %d\n\n%s",
            strlen($body),
            $body
        );
        $messages = $pipeline->decode($frame);

        return $messages[0];
    }

    private function decodeReplyFrame(): DecodedInboundMessage
    {
        $pipeline = InboundPipeline::withDefaults();
        $frame = "Content-Type: command/reply\nReply-Text: +OK accepted\n\n";
        $messages = $pipeline->decode($frame);

        return $messages[0];
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
