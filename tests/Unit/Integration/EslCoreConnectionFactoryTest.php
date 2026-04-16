<?php

namespace ApnTalk\LaravelFreeswitchEsl\Tests\Unit\Integration;

use Apntalk\EslCore\Commands\AuthCommand;
use Apntalk\EslCore\Commands\EventFormat;
use Apntalk\EslCore\Commands\EventSubscriptionCommand;
use Apntalk\EslCore\Contracts\TransportInterface;
use Apntalk\EslCore\Transport\InMemoryTransport;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\ConnectionContext;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslCoreCommandFactory;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslCoreConnectionFactory;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslCoreConnectionHandle;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslCorePipelineFactory;
use PHPUnit\Framework\TestCase;

class EslCoreConnectionFactoryTest extends TestCase
{
    public function test_create_returns_connection_handle(): void
    {
        $factory = $this->makeFactory();

        $handle = $factory->create($this->makeContext());

        $this->assertInstanceOf(EslCoreConnectionHandle::class, $handle);
    }

    public function test_handle_preserves_resolved_context(): void
    {
        $factory = $this->makeFactory();
        $context = $this->makeContext(workerSessionId: 'session-123');

        $handle = $factory->create($context);

        $this->assertSame($context, $handle->context);
        $this->assertSame('session-123', $handle->context->workerSessionId);
    }

    public function test_handle_uses_opening_sequence_from_resolved_context(): void
    {
        $factory = $this->makeFactory();

        $handle = $factory->create($this->makeContext(resolvedPassword: 'secret-pass'));
        $opening = $handle->openingSequence();

        $this->assertCount(2, $opening);
        $this->assertInstanceOf(AuthCommand::class, $opening[0]);
        $this->assertSame('secret-pass', $opening[0]->password());
        $this->assertInstanceOf(EventSubscriptionCommand::class, $opening[1]);
    }

    public function test_handle_respects_subscription_profile_event_names_and_format(): void
    {
        $factory = $this->makeFactory();
        $context = $this->makeContext(driverParameters: [
            'subscription' => [
                'event_names' => ['CHANNEL_CREATE', 'CHANNEL_HANGUP'],
                'format' => 'json',
            ],
        ]);

        $handle = $factory->create($context);

        /** @var EventSubscriptionCommand $subscription */
        $subscription = $handle->openingSequence()[1];

        $this->assertSame(['CHANNEL_CREATE', 'CHANNEL_HANGUP'], $subscription->eventNames());
        $this->assertSame(EventFormat::Json, $subscription->format());
    }

    public function test_handle_exposes_closing_sequence(): void
    {
        $factory = $this->makeFactory();

        $handle = $factory->create($this->makeContext());

        $this->assertCount(2, $handle->closingSequence());
    }

    public function test_open_transport_uses_injected_transport_opener_and_caches_transport(): void
    {
        $transport = new InMemoryTransport();
        $factory = $this->makeFactory(fn (): TransportInterface => $transport);

        $handle = $factory->create($this->makeContext());
        $first = $handle->openTransport();
        $second = $handle->openTransport();

        $this->assertSame($transport, $first);
        $this->assertSame($first, $second);
        $this->assertTrue($handle->hasOpenTransport());
    }

    public function test_close_transport_closes_and_clears_cached_transport(): void
    {
        $transport = new InMemoryTransport();
        $factory = $this->makeFactory(fn (): TransportInterface => $transport);

        $handle = $factory->create($this->makeContext());
        $handle->openTransport();
        $handle->closeTransport();

        $this->assertFalse($handle->hasOpenTransport());
    }

    public function test_endpoint_uses_context_transport_host_and_port(): void
    {
        $factory = $this->makeFactory();
        $context = $this->makeContext(host: '192.0.2.10', port: 9000, transport: 'tls');

        $handle = $factory->create($context);

        $this->assertSame('tls://192.0.2.10:9000', $handle->endpoint());
    }

    /**
     * @param  (\Closure(ConnectionContext): TransportInterface)|null  $transportOpener
     */
    private function makeFactory(?\Closure $transportOpener = null): EslCoreConnectionFactory
    {
        return new EslCoreConnectionFactory(
            commandFactory: new EslCoreCommandFactory(),
            pipelineFactory: new EslCorePipelineFactory(),
            transportOpener: $transportOpener,
        );
    }

    /**
     * @param  array<string, mixed>  $driverParameters
     */
    private function makeContext(
        string $resolvedPassword = 'ClueCon',
        string $host = '10.0.0.1',
        int $port = 8021,
        string $transport = 'tcp',
        array $driverParameters = [],
        ?string $workerSessionId = null,
    ): ConnectionContext {
        return new ConnectionContext(
            pbxNodeId: 1,
            pbxNodeSlug: 'test-node',
            providerCode: 'freeswitch',
            host: $host,
            port: $port,
            username: '',
            resolvedPassword: $resolvedPassword,
            transport: $transport,
            connectionProfileId: null,
            connectionProfileName: 'default',
            driverParameters: $driverParameters,
            workerSessionId: $workerSessionId,
        );
    }
}
