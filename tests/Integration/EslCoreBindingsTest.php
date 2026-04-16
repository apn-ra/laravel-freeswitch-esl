<?php

namespace ApnTalk\LaravelFreeswitchEsl\Tests\Integration;

use Apntalk\EslCore\Contracts\InboundConnectionFactoryInterface;
use Apntalk\EslCore\Contracts\InboundPipelineInterface;
use Apntalk\EslCore\Contracts\TransportFactoryInterface;
use Apntalk\EslCore\Correlation\ConnectionSessionId;
use Apntalk\EslCore\Inbound\PreparedInboundConnection;
use ApnTalk\LaravelFreeswitchEsl\Contracts\ConnectionFactoryInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\RuntimeHandoffInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\RuntimeRunnerInterface;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslCoreCommandFactory;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslCoreConnectionHandle;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslCoreEventBridge;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslCorePipelineFactory;
use ApnTalk\LaravelFreeswitchEsl\Integration\NonLiveRuntimeRunner;
use Apntalk\EslCore\Transport\SocketTransportFactory;
use ApnTalk\LaravelFreeswitchEsl\Tests\TestCase;

/**
 * Integration tests for apntalk/esl-core bindings in the Laravel container.
 *
 * Verifies that the service provider registers the integration types correctly
 * and that they can be resolved from the container in a real Testbench app.
 */
class EslCoreBindingsTest extends TestCase
{

    public function test_runtime_runner_resolves_from_container(): void
    {
        $runner = $this->app->make(RuntimeRunnerInterface::class);

        $this->assertInstanceOf(NonLiveRuntimeRunner::class, $runner);
    }

    public function test_runtime_runner_is_singleton(): void
    {
        $a = $this->app->make(RuntimeRunnerInterface::class);
        $b = $this->app->make(RuntimeRunnerInterface::class);

        $this->assertSame($a, $b);
    }

    public function test_esl_core_command_factory_resolves_from_container(): void
    {
        $factory = $this->app->make(EslCoreCommandFactory::class);

        $this->assertInstanceOf(EslCoreCommandFactory::class, $factory);
    }

    public function test_esl_core_command_factory_is_singleton(): void
    {
        $a = $this->app->make(EslCoreCommandFactory::class);
        $b = $this->app->make(EslCoreCommandFactory::class);

        $this->assertSame($a, $b);
    }

    public function test_transport_factory_resolves_from_container(): void
    {
        $factory = $this->app->make(TransportFactoryInterface::class);

        $this->assertInstanceOf(SocketTransportFactory::class, $factory);
    }

    public function test_transport_factory_is_singleton(): void
    {
        $a = $this->app->make(TransportFactoryInterface::class);
        $b = $this->app->make(TransportFactoryInterface::class);

        $this->assertSame($a, $b);
    }

    public function test_inbound_connection_factory_resolves_from_container(): void
    {
        $factory = $this->app->make(InboundConnectionFactoryInterface::class);

        $this->assertInstanceOf(InboundConnectionFactoryInterface::class, $factory);
    }

    public function test_inbound_connection_factory_is_singleton(): void
    {
        $a = $this->app->make(InboundConnectionFactoryInterface::class);
        $b = $this->app->make(InboundConnectionFactoryInterface::class);

        $this->assertSame($a, $b);
    }

    public function test_inbound_connection_factory_prepares_accepted_stream_bundle(): void
    {
        $factory = $this->app->make(InboundConnectionFactoryInterface::class);
        $stream = fopen('php://temp', 'r+');

        $prepared = $factory->prepareAcceptedStream($stream);

        $this->assertInstanceOf(PreparedInboundConnection::class, $prepared);
        $this->assertInstanceOf(InboundPipelineInterface::class, $prepared->pipeline());
        $this->assertSame(0, $prepared->pipeline()->bufferedByteCount());
        $this->assertInstanceOf(ConnectionSessionId::class, $prepared->sessionId());
    }

    public function test_esl_core_pipeline_factory_resolves_from_container(): void
    {
        $factory = $this->app->make(EslCorePipelineFactory::class);

        $this->assertInstanceOf(EslCorePipelineFactory::class, $factory);
    }

    public function test_esl_core_pipeline_factory_is_singleton(): void
    {
        $a = $this->app->make(EslCorePipelineFactory::class);
        $b = $this->app->make(EslCorePipelineFactory::class);

        $this->assertSame($a, $b);
    }

    public function test_esl_core_pipeline_factory_produces_functional_pipelines(): void
    {
        $factory = $this->app->make(EslCorePipelineFactory::class);
        $pipeline = $factory->createPipeline();

        $this->assertInstanceOf(InboundPipelineInterface::class, $pipeline);
        $this->assertSame(0, $pipeline->bufferedByteCount());
    }

    public function test_esl_core_event_bridge_resolves_from_container(): void
    {
        $bridge = $this->app->make(EslCoreEventBridge::class);

        $this->assertInstanceOf(EslCoreEventBridge::class, $bridge);
    }

    public function test_esl_core_event_bridge_is_singleton(): void
    {
        $a = $this->app->make(EslCoreEventBridge::class);
        $b = $this->app->make(EslCoreEventBridge::class);

        $this->assertSame($a, $b);
    }

    public function test_command_factory_can_build_commands_after_container_resolution(): void
    {
        $factory = $this->app->make(EslCoreCommandFactory::class);
        $command = $factory->buildAuthCommand('ClueCon');

        $this->assertSame("auth ClueCon\n\n", $command->serialize());
    }

    public function test_connection_factory_resolves_from_container_and_creates_runtime_handoff(): void
    {
        $factory = $this->app->make(ConnectionFactoryInterface::class);

        $handle = $factory->create(new \ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\ConnectionContext(
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
        ));

        $this->assertInstanceOf(RuntimeHandoffInterface::class, $handle);
        $this->assertInstanceOf(EslCoreConnectionHandle::class, $handle);
        $this->assertSame('tcp://10.0.0.1:8021', $handle->endpoint());
    }
}
