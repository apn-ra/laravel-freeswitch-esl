<?php

namespace ApnTalk\LaravelFreeswitchEsl\Tests\Unit\Integration;

use Apntalk\EslReact\Runner\PreparedRuntimeBootstrapInput;
use Apntalk\EslReplay\Contracts\ReplayArtifactStoreInterface;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\ConnectionContext;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslCoreConnectionHandle;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslCorePipelineFactory;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslReactRuntimeBootstrapInputFactory;
use ApnTalk\LaravelFreeswitchEsl\Integration\Replay\ReplayCaptureSinkFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use React\Socket\ConnectorInterface;

class EslReactRuntimeBootstrapInputFactoryTest extends TestCase
{
    public function test_create_maps_runtime_handoff_to_prepared_esl_react_input(): void
    {
        $connector = $this->createMock(ConnectorInterface::class);
        $handoff = $this->makeHandoff($this->makeContext(
            resolvedPassword: 'secret-pass',
            host: '203.0.113.10',
            port: 8022,
            driverParameters: [
                'subscription' => [
                    'event_names' => ['CHANNEL_CREATE', 'CHANNEL_HANGUP'],
                ],
            ],
            workerSessionId: 'worker-session-1',
        ));
        $factory = new EslReactRuntimeBootstrapInputFactory(connector: $connector);

        $input = $factory->create($handoff);

        $this->assertInstanceOf(PreparedRuntimeBootstrapInput::class, $input);
        $this->assertSame('tcp://203.0.113.10:8022', $input->endpoint());
        $this->assertSame('tcp://203.0.113.10:8022', $input->dialUri());
        $this->assertSame('203.0.113.10', $input->runtimeConfig()->host);
        $this->assertSame(8022, $input->runtimeConfig()->port);
        $this->assertSame('secret-pass', $input->runtimeConfig()->password);
        $this->assertSame(['CHANNEL_CREATE', 'CHANNEL_HANGUP'], $input->runtimeConfig()->subscriptions->initialEventNames);
        $this->assertFalse($input->runtimeConfig()->subscriptions->subscribeAll);
        $this->assertSame($handoff->pipeline(), $input->inboundPipeline());
        $this->assertSame($connector, $input->connector());
        $this->assertSame('worker-session-1', $input->sessionContext()->sessionId());
        $this->assertSame([
            'provider_code' => 'freeswitch',
            'pbx_node_id' => 10,
            'pbx_node_slug' => 'node-a',
            'connection_profile_id' => 20,
            'connection_profile_name' => 'primary',
            'worker_session_id' => 'worker-session-1',
            'transport' => 'tcp',
        ], $input->sessionContext()->metadata());
    }

    public function test_create_subscribes_all_when_no_event_names_are_configured(): void
    {
        $input = (new EslReactRuntimeBootstrapInputFactory(
            connector: $this->createMock(ConnectorInterface::class),
        ))->create($this->makeHandoff($this->makeContext()));

        $this->assertTrue($input->runtimeConfig()->subscriptions->subscribeAll);
        $this->assertSame([], $input->runtimeConfig()->subscriptions->initialEventNames);
    }

    public function test_create_maps_tls_handoff_to_explicit_dial_uri(): void
    {
        $factory = new EslReactRuntimeBootstrapInputFactory(
            connector: $this->createMock(ConnectorInterface::class),
        );

        $input = $factory->create($this->makeHandoff($this->makeContext(
            host: '192.0.2.10',
            port: 7443,
            transport: 'tls',
        )));

        $this->assertSame('tls://192.0.2.10:7443', $input->endpoint());
        $this->assertSame('tls://192.0.2.10:7443', $input->dialUri());
        $this->assertSame('tcp://192.0.2.10:7443', $input->runtimeConfig()->connectionUri());
    }

    public function test_create_fails_closed_for_unsupported_explicit_dial_target_transport(): void
    {
        $factory = new EslReactRuntimeBootstrapInputFactory(
            connector: $this->createMock(ConnectorInterface::class),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not support explicit dial URI mapping');

        $factory->create($this->makeHandoff($this->makeContext(transport: 'unix')));
    }

    public function test_create_enables_replay_capture_when_sink_factory_is_available(): void
    {
        $store = $this->createMock(ReplayArtifactStoreInterface::class);
        $factory = new EslReactRuntimeBootstrapInputFactory(
            connector: $this->createMock(ConnectorInterface::class),
            replayCaptureSinkFactory: new ReplayCaptureSinkFactory($store, new NullLogger),
            replayCaptureEnabled: true,
        );

        $input = $factory->create($this->makeHandoff($this->makeContext()));

        $this->assertTrue($input->runtimeConfig()->replayCaptureEnabled);
        $this->assertCount(1, $input->runtimeConfig()->replayCaptureSinks);
    }

    public function test_create_projects_timeout_and_stream_context_options_to_live_react_connector(): void
    {
        $factory = new EslReactRuntimeBootstrapInputFactory(
            connectorOptions: [
                'dns' => false,
                'happy_eyeballs' => false,
                'timeout' => 9.0,
                'tcp' => ['bindto' => '0:0'],
                'tls' => ['verify_peer' => true],
            ],
        );

        $input = $factory->create($this->makeHandoff($this->makeContext(
            host: '192.0.2.10',
            port: 7443,
            transport: 'tls',
            driverParameters: [
                'connect_timeout_seconds' => 4.5,
                'stream_context_options' => [
                    'socket' => ['tcp_nodelay' => true],
                    'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
                ],
            ],
        )));

        $connector = $input->connector();
        $connectors = $this->readPrivateProperty($connector, 'connectors');
        $this->assertIsArray($connectors);

        $tcpConnector = $connectors['tcp'];
        $tlsConnector = $connectors['tls'];

        $this->assertSame(4.5, $this->readPrivateProperty($tcpConnector, 'timeout'));
        $this->assertSame(4.5, $this->readPrivateProperty($tlsConnector, 'timeout'));

        $wrappedTcpConnector = $this->readPrivateProperty($tcpConnector, 'connector');
        $wrappedTlsConnector = $this->readPrivateProperty($tlsConnector, 'connector');

        $this->assertSame(
            ['bindto' => '0:0', 'tcp_nodelay' => true],
            $this->readPrivateProperty($wrappedTcpConnector, 'context')
        );
        $this->assertSame(
            ['verify_peer' => false, 'verify_peer_name' => false],
            $this->readPrivateProperty($wrappedTlsConnector, 'context')
        );
    }

    public function test_create_does_not_override_non_array_connector_entries_when_projecting_context_options(): void
    {
        $factory = new EslReactRuntimeBootstrapInputFactory(
            connectorOptions: [
                'dns' => false,
                'happy_eyeballs' => false,
                'tcp' => false,
            ],
        );

        $input = $factory->create($this->makeHandoff($this->makeContext(
            driverParameters: [
                'stream_context_options' => [
                    'socket' => ['tcp_nodelay' => true],
                ],
            ],
        )));

        $connector = $input->connector();
        $connectors = $this->readPrivateProperty($connector, 'connectors');

        $this->assertIsArray($connectors);
        $this->assertArrayNotHasKey('tcp', $connectors);
    }

    private function makeHandoff(ConnectionContext $context): EslCoreConnectionHandle
    {
        return new EslCoreConnectionHandle(
            context: $context,
            pipeline: (new EslCorePipelineFactory)->createPipeline(),
            openingSequence: [],
            closingSequence: [],
            transportOpener: fn () => throw new \LogicException('Transport opener must not be called by esl-react input mapping.'),
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
        ?string $workerSessionId = 'worker-session',
    ): ConnectionContext {
        return new ConnectionContext(
            pbxNodeId: 10,
            pbxNodeSlug: 'node-a',
            providerCode: 'freeswitch',
            host: $host,
            port: $port,
            username: '',
            resolvedPassword: $resolvedPassword,
            transport: $transport,
            connectionProfileId: 20,
            connectionProfileName: 'primary',
            driverParameters: $driverParameters,
            workerSessionId: $workerSessionId,
        );
    }

    private function readPrivateProperty(object $object, string $name): mixed
    {
        $reflection = new \ReflectionObject($object);

        while (! $reflection->hasProperty($name) && ($reflection = $reflection->getParentClass()) !== false) {
        }

        if (! $reflection instanceof \ReflectionClass) {
            throw new \RuntimeException(sprintf('Property [%s] not found.', $name));
        }

        $property = $reflection->getProperty($name);
        $property->setAccessible(true);

        return $property->getValue($object);
    }
}
