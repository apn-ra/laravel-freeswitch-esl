<?php

namespace ApnTalk\LaravelFreeswitchEsl\Integration;

use Apntalk\EslCore\Inbound\DecodedInboundMessage;
use Apntalk\EslCore\Inbound\InboundMessageType;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\ConnectionContext;
use ApnTalk\LaravelFreeswitchEsl\Events\EslDisconnected;
use ApnTalk\LaravelFreeswitchEsl\Events\EslEventReceived;
use ApnTalk\LaravelFreeswitchEsl\Events\EslReplyReceived;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Converts decoded apntalk/esl-core messages into Laravel events.
 *
 * This bridge is the integration point between the esl-core inbound pipeline
 * and the Laravel event system. It is called by the runtime layer (esl-react,
 * 0.3.x) for each decoded message, and dispatches typed Laravel events that
 * application code can listen to.
 *
 * Ownership model:
 *   - This bridge is owned by this Laravel package.
 *   - DecodedInboundMessage and its typed payload types come from apntalk/esl-core.
 *   - The Laravel event classes (EslEventReceived, etc.) are owned by this package.
 *   - The dispatch call target (Dispatcher) is the Laravel event system.
 *
 * Each dispatched event carries:
 *   - the esl-core typed payload (event/reply)
 *   - the ConnectionContext for runtime identity (provider, node, session)
 *
 * Boundary: do NOT add transport I/O, reconnect logic, or frame parsing here.
 */
final class EslCoreEventBridge
{
    public function __construct(
        private readonly Dispatcher $events,
    ) {}

    /**
     * Dispatch a Laravel event for the given decoded inbound message.
     *
     * - Event messages → EslEventReceived
     * - Reply messages → EslReplyReceived
     * - Disconnect notices → EslDisconnected
     * - Auth requests and unknowns → not dispatched (handled by runtime)
     *
     * Auth requests and unknown messages are intentionally excluded: auth
     * is a handshake step managed by the runtime (esl-react), not by the
     * application event system. Application code should listen to
     * EslEventReceived and EslReplyReceived only.
     */
    public function dispatch(DecodedInboundMessage $message, ConnectionContext $context): void
    {
        match ($message->type()) {
            InboundMessageType::Event => $this->dispatchEvent($message, $context),
            InboundMessageType::Reply => $this->dispatchReply($message, $context),
            InboundMessageType::DisconnectNotice => $this->dispatchDisconnect($context),
            default => null, // ServerAuthRequest and Unknown handled by the runtime
        };
    }

    /**
     * Dispatch a batch of decoded messages.
     *
     * Convenience wrapper for processing all drained messages from the pipeline.
     *
     * @param  list<DecodedInboundMessage>  $messages
     */
    public function dispatchAll(array $messages, ConnectionContext $context): void
    {
        foreach ($messages as $message) {
            $this->dispatch($message, $context);
        }
    }

    private function dispatchEvent(DecodedInboundMessage $message, ConnectionContext $context): void
    {
        $normalizedEvent = $message->normalizedEvent();
        $typedEvent = $message->event();

        if ($normalizedEvent === null || $typedEvent === null) {
            return;
        }

        $this->events->dispatch(new EslEventReceived($typedEvent, $normalizedEvent, $context));
    }

    private function dispatchReply(DecodedInboundMessage $message, ConnectionContext $context): void
    {
        $reply = $message->reply();

        if ($reply === null) {
            return;
        }

        $this->events->dispatch(new EslReplyReceived($reply, $context));
    }

    private function dispatchDisconnect(ConnectionContext $context): void
    {
        $this->events->dispatch(new EslDisconnected($context));
    }
}
