<?php

namespace ApnTalk\LaravelFreeswitchEsl\Integration;

use Apntalk\EslCore\Commands\ApiCommand;
use Apntalk\EslCore\Commands\AuthCommand;
use Apntalk\EslCore\Commands\BgapiCommand;
use Apntalk\EslCore\Commands\EventFormat;
use Apntalk\EslCore\Commands\EventSubscriptionCommand;
use Apntalk\EslCore\Commands\ExitCommand;
use Apntalk\EslCore\Commands\FilterCommand;
use Apntalk\EslCore\Commands\NoEventsCommand;
use Apntalk\EslCore\Contracts\CommandInterface;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\ConnectionContext;

/**
 * Builds typed apntalk/esl-core command objects for use at the Laravel
 * package boundary.
 *
 * This factory is the primary integration point between the Laravel control
 * plane (ConnectionContext, profiles) and esl-core's typed command hierarchy.
 *
 * Ownership model:
 *   - This factory is owned by this Laravel package.
 *   - The command types (AuthCommand, ApiCommand, etc.) are owned by apntalk/esl-core.
 *   - Transport and dispatch are owned by apntalk/esl-react.
 *
 * Boundary: do NOT add ESL frame parsing, transport I/O, or reconnect logic here.
 */
final class EslCoreCommandFactory
{
    /**
     * Build an auth command from a resolved credential.
     */
    public function buildAuthCommand(string $password): AuthCommand
    {
        return new AuthCommand($password);
    }

    /**
     * Build a synchronous API command.
     */
    public function buildApiCommand(string $command, string $args = ''): ApiCommand
    {
        return new ApiCommand($command, $args);
    }

    /**
     * Build a background API command.
     *
     * The acceptance reply carries a Job-UUID. The actual result arrives
     * later as a BACKGROUND_JOB event correlated by that UUID.
     */
    public function buildBgapiCommand(string $command, string $args = ''): BgapiCommand
    {
        return new BgapiCommand($command, $args);
    }

    /**
     * Build a subscription command for all events (plain format).
     */
    public function buildSubscribeAll(EventFormat $format = EventFormat::Plain): EventSubscriptionCommand
    {
        return EventSubscriptionCommand::all($format);
    }

    /**
     * Build a subscription command for specific named events.
     *
     * @param  list<string>  $eventNames
     */
    public function buildSubscribeForNames(array $eventNames, EventFormat $format = EventFormat::Plain): EventSubscriptionCommand
    {
        return EventSubscriptionCommand::forNames($eventNames, $format);
    }

    /**
     * Build a filter add command.
     *
     * Filters restrict which events are delivered to the connection.
     */
    public function buildFilterAdd(string $headerName, string $headerValue): FilterCommand
    {
        return FilterCommand::add($headerName, $headerValue);
    }

    /**
     * Build a filter delete command.
     */
    public function buildFilterDelete(string $headerName, string $headerValue): FilterCommand
    {
        return FilterCommand::delete($headerName, $headerValue);
    }

    /**
     * Build an exit command.
     *
     * Signals FreeSWITCH to close the ESL connection gracefully.
     */
    public function buildExitCommand(): ExitCommand
    {
        return new ExitCommand();
    }

    /**
     * Build a no-events command.
     *
     * Cancels all active event subscriptions for this connection.
     */
    public function buildNoEventsCommand(): NoEventsCommand
    {
        return new NoEventsCommand();
    }

    /**
     * Build the opening command sequence for a FreeSWITCH ESL session.
     *
     * The sequence covers:
     *   1. Auth (using the resolved credential from ConnectionContext)
     *   2. Event subscription (subscribe-all by default)
     *
     * This sequence is intended to be sent after a successful transport
     * connection. The auth command must be sent first; only after the
     * auth-accepted reply may other commands be dispatched.
     *
     * The actual transport send is owned by apntalk/esl-react.
     * This method produces the typed objects; dispatch is the caller's concern.
     *
     * @param  list<string>  $eventNames  Empty means subscribe to all events.
     * @return list<CommandInterface>
     */
    public function buildOpeningSequence(
        ConnectionContext $context,
        array $eventNames = [],
        EventFormat $format = EventFormat::Plain,
    ): array {
        $commands = [];

        $commands[] = $this->buildAuthCommand($context->resolvedPassword);

        if (empty($eventNames)) {
            $commands[] = $this->buildSubscribeAll($format);
        } else {
            $commands[] = $this->buildSubscribeForNames($eventNames, $format);
        }

        return $commands;
    }

    /**
     * Build the closing command sequence for a FreeSWITCH ESL session.
     *
     * Sends a no-events command (cancels subscriptions) then an exit command.
     * During drain, the runtime may choose to send only the exit.
     *
     * @return list<CommandInterface>
     */
    public function buildClosingSequence(): array
    {
        return [
            $this->buildNoEventsCommand(),
            $this->buildExitCommand(),
        ];
    }
}
