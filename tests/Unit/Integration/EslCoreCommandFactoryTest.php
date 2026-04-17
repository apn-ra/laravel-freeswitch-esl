<?php

namespace ApnTalk\LaravelFreeswitchEsl\Tests\Unit\Integration;

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
use ApnTalk\LaravelFreeswitchEsl\Integration\EslCoreCommandFactory;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for EslCoreCommandFactory.
 *
 * Verifies that the factory produces correctly-typed esl-core command objects
 * and that the opening/closing sequence helpers behave consistently with the
 * esl-core contract.
 */
class EslCoreCommandFactoryTest extends TestCase
{
    private EslCoreCommandFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new EslCoreCommandFactory;
    }

    public function test_build_auth_command_returns_auth_command(): void
    {
        $command = $this->factory->buildAuthCommand('ClueCon');

        $this->assertInstanceOf(AuthCommand::class, $command);
        $this->assertInstanceOf(CommandInterface::class, $command);
        $this->assertSame('ClueCon', $command->password());
    }

    public function test_build_auth_command_serializes_correctly(): void
    {
        $command = $this->factory->buildAuthCommand('ClueCon');

        $this->assertSame("auth ClueCon\n\n", $command->serialize());
    }

    public function test_build_api_command_returns_api_command(): void
    {
        $command = $this->factory->buildApiCommand('status');

        $this->assertInstanceOf(ApiCommand::class, $command);
        $this->assertSame('status', $command->command());
        $this->assertSame('', $command->args());
    }

    public function test_build_api_command_with_args_serializes_correctly(): void
    {
        $command = $this->factory->buildApiCommand('sofia', 'status');

        $this->assertSame("api sofia status\n\n", $command->serialize());
    }

    public function test_build_bgapi_command_returns_bgapi_command(): void
    {
        $command = $this->factory->buildBgapiCommand('originate', 'user/alice &echo');

        $this->assertInstanceOf(BgapiCommand::class, $command);
        $this->assertSame('originate', $command->command());
        $this->assertSame('user/alice &echo', $command->args());
    }

    public function test_build_bgapi_command_serializes_correctly(): void
    {
        $command = $this->factory->buildBgapiCommand('originate', 'user/alice &echo');

        $this->assertSame("bgapi originate user/alice &echo\n\n", $command->serialize());
    }

    public function test_build_subscribe_all_returns_subscribe_all_command(): void
    {
        $command = $this->factory->buildSubscribeAll();

        $this->assertInstanceOf(EventSubscriptionCommand::class, $command);
        $this->assertTrue($command->isAllEvents());
        $this->assertSame("event plain all\n\n", $command->serialize());
    }

    public function test_build_subscribe_all_respects_format(): void
    {
        $command = $this->factory->buildSubscribeAll(EventFormat::Json);

        $this->assertSame("event json all\n\n", $command->serialize());
    }

    public function test_build_subscribe_for_names_returns_named_subscription(): void
    {
        $command = $this->factory->buildSubscribeForNames(['CHANNEL_CREATE', 'CHANNEL_HANGUP']);

        $this->assertInstanceOf(EventSubscriptionCommand::class, $command);
        $this->assertFalse($command->isAllEvents());
        $this->assertSame(['CHANNEL_CREATE', 'CHANNEL_HANGUP'], $command->eventNames());
    }

    public function test_build_subscribe_for_names_serializes_correctly(): void
    {
        $command = $this->factory->buildSubscribeForNames(['CHANNEL_CREATE', 'CHANNEL_HANGUP']);

        $this->assertSame("event plain CHANNEL_CREATE CHANNEL_HANGUP\n\n", $command->serialize());
    }

    public function test_build_filter_add_returns_filter_command(): void
    {
        $command = $this->factory->buildFilterAdd('Event-Name', 'CHANNEL_CREATE');

        $this->assertInstanceOf(FilterCommand::class, $command);
        $this->assertStringContainsString('filter', $command->serialize());
        $this->assertStringContainsString('Event-Name', $command->serialize());
        $this->assertStringContainsString('CHANNEL_CREATE', $command->serialize());
    }

    public function test_build_filter_delete_returns_filter_delete_command(): void
    {
        $command = $this->factory->buildFilterDelete('Event-Name', 'CHANNEL_CREATE');

        $this->assertInstanceOf(FilterCommand::class, $command);
        $this->assertStringContainsString('delete', $command->serialize());
    }

    public function test_build_exit_command_returns_exit_command(): void
    {
        $command = $this->factory->buildExitCommand();

        $this->assertInstanceOf(ExitCommand::class, $command);
        $this->assertSame("exit\n\n", $command->serialize());
    }

    public function test_build_no_events_command_returns_no_events_command(): void
    {
        $command = $this->factory->buildNoEventsCommand();

        $this->assertInstanceOf(NoEventsCommand::class, $command);
        $this->assertSame("noevents\n\n", $command->serialize());
    }

    public function test_opening_sequence_contains_auth_then_subscribe(): void
    {
        $context = $this->makeContext(resolvedPassword: 'ClueCon');
        $sequence = $this->factory->buildOpeningSequence($context);

        $this->assertCount(2, $sequence);
        $this->assertInstanceOf(AuthCommand::class, $sequence[0]);
        $this->assertInstanceOf(EventSubscriptionCommand::class, $sequence[1]);
    }

    public function test_opening_sequence_uses_resolved_password_from_context(): void
    {
        $context = $this->makeContext(resolvedPassword: 'secret-password');
        $sequence = $this->factory->buildOpeningSequence($context);

        /** @var AuthCommand $auth */
        $auth = $sequence[0];
        $this->assertSame('secret-password', $auth->password());
    }

    public function test_opening_sequence_with_no_event_names_subscribes_all(): void
    {
        $context = $this->makeContext();
        $sequence = $this->factory->buildOpeningSequence($context, []);

        /** @var EventSubscriptionCommand $subscribe */
        $subscribe = $sequence[1];
        $this->assertTrue($subscribe->isAllEvents());
    }

    public function test_opening_sequence_with_event_names_subscribes_named(): void
    {
        $context = $this->makeContext();
        $sequence = $this->factory->buildOpeningSequence($context, ['CHANNEL_CREATE', 'CHANNEL_HANGUP']);

        /** @var EventSubscriptionCommand $subscribe */
        $subscribe = $sequence[1];
        $this->assertFalse($subscribe->isAllEvents());
        $this->assertSame(['CHANNEL_CREATE', 'CHANNEL_HANGUP'], $subscribe->eventNames());
    }

    public function test_opening_sequence_all_items_implement_command_interface(): void
    {
        $context = $this->makeContext();
        $sequence = $this->factory->buildOpeningSequence($context);

        foreach ($sequence as $command) {
            $this->assertInstanceOf(CommandInterface::class, $command);
            $this->assertStringEndsWith("\n\n", $command->serialize());
        }
    }

    public function test_closing_sequence_contains_no_events_then_exit(): void
    {
        $sequence = $this->factory->buildClosingSequence();

        $this->assertCount(2, $sequence);
        $this->assertInstanceOf(NoEventsCommand::class, $sequence[0]);
        $this->assertInstanceOf(ExitCommand::class, $sequence[1]);
    }

    public function test_all_command_serializations_end_with_double_newline(): void
    {
        $context = $this->makeContext(resolvedPassword: 'ClueCon');

        $commands = [
            $this->factory->buildAuthCommand('ClueCon'),
            $this->factory->buildApiCommand('status'),
            $this->factory->buildBgapiCommand('originate', 'user/alice &echo'),
            $this->factory->buildSubscribeAll(),
            $this->factory->buildSubscribeForNames(['CHANNEL_CREATE']),
            $this->factory->buildFilterAdd('Event-Name', 'CHANNEL_CREATE'),
            $this->factory->buildFilterDelete('Event-Name', 'CHANNEL_CREATE'),
            $this->factory->buildExitCommand(),
            $this->factory->buildNoEventsCommand(),
            ...$this->factory->buildOpeningSequence($context),
            ...$this->factory->buildClosingSequence(),
        ];

        foreach ($commands as $command) {
            $this->assertStringEndsWith(
                "\n\n",
                $command->serialize(),
                sprintf('%s serialization must end with \\n\\n', get_class($command))
            );
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeContext(string $resolvedPassword = 'ClueCon'): ConnectionContext
    {
        return new ConnectionContext(
            pbxNodeId: 1,
            pbxNodeSlug: 'test-node',
            providerCode: 'freeswitch',
            host: '10.0.0.1',
            port: 8021,
            username: '',
            resolvedPassword: $resolvedPassword,
            transport: 'tcp',
            connectionProfileId: null,
            connectionProfileName: 'default',
        );
    }
}
