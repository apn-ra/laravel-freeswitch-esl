<?php

namespace ApnTalk\LaravelFreeswitchEsl\Tests\Unit\Integration;

use Apntalk\EslCore\Commands\AuthCommand;
use Apntalk\EslCore\Commands\EventFormat;
use Apntalk\EslCore\Commands\EventSubscriptionCommand;
use Apntalk\EslCore\Contracts\TransportFactoryInterface;
use Apntalk\EslCore\Contracts\TransportInterface;
use Apntalk\EslCore\Exceptions\TransportException;
use Apntalk\EslCore\Transport\SocketEndpoint;
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
        $transportFactory = new class ($transport) implements TransportFactoryInterface {
            public int $connectCalls = 0;

            public function __construct(private readonly TransportInterface $transport) {}

            public function connect(SocketEndpoint $endpoint): TransportInterface
            {
                $this->connectCalls++;

                return $this->transport;
            }

            public function fromStream($stream): TransportInterface
            {
                return $this->transport;
            }
        };
        $factory = $this->makeFactory($transportFactory);

        $handle = $factory->create($this->makeContext());
        $first = $handle->openTransport();
        $second = $handle->openTransport();

        $this->assertSame($transport, $first);
        $this->assertSame($first, $second);
        $this->assertTrue($handle->hasOpenTransport());
        $this->assertSame(1, $transportFactory->connectCalls);
    }

    public function test_close_transport_closes_and_clears_cached_transport(): void
    {
        $transport = new InMemoryTransport();
        $transportFactory = new class ($transport) implements TransportFactoryInterface {
            public function __construct(private readonly TransportInterface $transport) {}

            public function connect(SocketEndpoint $endpoint): TransportInterface
            {
                return $this->transport;
            }

            public function fromStream($stream): TransportInterface
            {
                return $this->transport;
            }
        };
        $factory = $this->makeFactory($transportFactory);

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

    public function test_open_transport_uses_public_socket_endpoint_construction_for_tcp(): void
    {
        $transport = new InMemoryTransport();
        $transportFactory = new class ($transport) implements TransportFactoryInterface {
            public ?SocketEndpoint $endpoint = null;

            public function __construct(private readonly TransportInterface $transport) {}

            public function connect(SocketEndpoint $endpoint): TransportInterface
            {
                $this->endpoint = $endpoint;

                return $this->transport;
            }

            public function fromStream($stream): TransportInterface
            {
                return $this->transport;
            }
        };

        $factory = $this->makeFactory($transportFactory);
        $handle = $factory->create($this->makeContext(host: '203.0.113.10', port: 8022));
        $handle->openTransport();

        $this->assertNotNull($transportFactory->endpoint);
        $this->assertSame('tcp://203.0.113.10:8022', $transportFactory->endpoint->address());
        $this->assertSame(10.0, $transportFactory->endpoint->timeoutSeconds());
    }

    public function test_open_transport_uses_public_socket_endpoint_construction_for_tls(): void
    {
        $transport = new InMemoryTransport();
        $transportFactory = new class ($transport) implements TransportFactoryInterface {
            public ?SocketEndpoint $endpoint = null;

            public function __construct(private readonly TransportInterface $transport) {}

            public function connect(SocketEndpoint $endpoint): TransportInterface
            {
                $this->endpoint = $endpoint;

                return $this->transport;
            }

            public function fromStream($stream): TransportInterface
            {
                return $this->transport;
            }
        };

        $factory = $this->makeFactory($transportFactory);
        $handle = $factory->create($this->makeContext(
            host: '198.51.100.5',
            port: 7443,
            transport: 'tls',
            driverParameters: ['stream_context_options' => ['ssl' => ['verify_peer' => false]]],
        ));
        $handle->openTransport();

        $this->assertNotNull($transportFactory->endpoint);
        $this->assertSame('tls://198.51.100.5:7443', $transportFactory->endpoint->address());
        $this->assertSame(['ssl' => ['verify_peer' => false]], $transportFactory->endpoint->contextOptions());
    }

    public function test_open_transport_throws_for_unsupported_transport(): void
    {
        $factory = $this->makeFactory();
        $handle = $factory->create($this->makeContext(transport: 'udp'));

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Unsupported transport [udp]');

        $handle->openTransport();
    }

    /**
     */
    private function makeFactory(?TransportFactoryInterface $transportFactory = null): EslCoreConnectionFactory
    {
        return new EslCoreConnectionFactory(
            commandFactory: new EslCoreCommandFactory(),
            pipelineFactory: new EslCorePipelineFactory(),
            transportFactory: $transportFactory ?? new class implements TransportFactoryInterface {
                public function connect(SocketEndpoint $endpoint): TransportInterface
                {
                    return new InMemoryTransport();
                }

                public function fromStream($stream): TransportInterface
                {
                    return new InMemoryTransport();
                }
            },
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
