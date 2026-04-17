<?php

namespace ApnTalk\LaravelFreeswitchEsl\Tests\Unit\Integration;

use Apntalk\EslReact\Contracts\AsyncEslClientInterface;
use Apntalk\EslReact\Contracts\HealthReporterInterface;
use Apntalk\EslReact\Contracts\RuntimeRunnerInputInterface;
use Apntalk\EslReact\Contracts\RuntimeRunnerInterface as EslReactRuntimeRunnerInterface;
use Apntalk\EslReact\Connection\ConnectionState;
use Apntalk\EslReact\Health\HealthSnapshot;
use Apntalk\EslReact\Runner\PreparedRuntimeBootstrapInput;
use Apntalk\EslReact\Runner\RuntimeRunnerHandle;
use Apntalk\EslReact\Session\SessionState;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\RuntimeRunnerFeedback;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\ConnectionContext;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslCoreConnectionHandle;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslCorePipelineFactory;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslReactRuntimeBootstrapInputFactory;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslReactRuntimeRunnerAdapter;
use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\Socket\ConnectorInterface;

use function React\Promise\resolve;

class EslReactRuntimeRunnerAdapterTest extends TestCase
{
    public function test_run_adapts_laravel_handoff_and_invokes_esl_react_runner(): void
    {
        $connector = $this->createMock(ConnectorInterface::class);
        $healthReporter = $this->createStub(HealthReporterInterface::class);
        $healthReporter
            ->method('snapshot')
            ->willReturn(new HealthSnapshot(
                connectionState: ConnectionState::Authenticated,
                sessionState: SessionState::Active,
                isLive: true,
                inflightCommandCount: 0,
                pendingBgapiJobCount: 0,
                totalInflightCount: 0,
                isOverloaded: false,
                activeSubscriptions: [],
                reconnectAttempts: 2,
                isDraining: false,
                lastErrorClass: null,
                lastErrorMessage: null,
                snapshotAtMicros: 1000.0,
                lastHeartbeatAtMicros: 900.0,
            ));

        $client = $this->createStub(AsyncEslClientInterface::class);
        $client->method('health')->willReturn($healthReporter);

        $upstreamRunner = new class ($client) implements EslReactRuntimeRunnerInterface {
            public ?RuntimeRunnerInputInterface $input = null;

            public function __construct(private readonly AsyncEslClientInterface $client) {}

            public function run(RuntimeRunnerInputInterface $input, ?LoopInterface $loop = null): RuntimeRunnerHandle
            {
                $this->input = $input;

                return new RuntimeRunnerHandle(
                    endpoint: $input->endpoint(),
                    client: $this->client,
                    startupPromise: resolve(null),
                    sessionContext: $input instanceof PreparedRuntimeBootstrapInput ? $input->sessionContext() : null,
                );
            }
        };
        $adapter = new EslReactRuntimeRunnerAdapter(
            runner: $upstreamRunner,
            inputFactory: new EslReactRuntimeBootstrapInputFactory(connector: $connector),
        );
        $handoff = new EslCoreConnectionHandle(
            context: new ConnectionContext(
                pbxNodeId: 10,
                pbxNodeSlug: 'node-a',
                providerCode: 'freeswitch',
                host: '203.0.113.10',
                port: 8021,
                username: '',
                resolvedPassword: 'secret',
                transport: 'tcp',
                connectionProfileId: 20,
                connectionProfileName: 'primary',
                workerSessionId: 'worker-session-1',
            ),
            pipeline: (new EslCorePipelineFactory())->createPipeline(),
            openingSequence: [],
            closingSequence: [],
            transportOpener: fn () => throw new \LogicException('Transport opener must not be called by adapter.'),
        );

        $adapter->run($handoff);

        $this->assertInstanceOf(PreparedRuntimeBootstrapInput::class, $upstreamRunner->input);
        $this->assertSame('tcp://203.0.113.10:8021', $upstreamRunner->input->endpoint());
        $this->assertNotNull($adapter->lastHandle());
        $this->assertSame('worker-session-1', $adapter->lastHandle()->sessionContext()?->sessionId());
        $this->assertNotNull($adapter->runtimeFeedback());
        $this->assertSame(RuntimeRunnerFeedback::STATE_RUNNING, $adapter->runtimeFeedback()->state);
        $this->assertSame('apntalk/esl-react-runtime-lifecycle-snapshot', $adapter->runtimeFeedback()->source);
        $this->assertSame('worker-session-1', $adapter->runtimeFeedback()->sessionId);
        $this->assertTrue($adapter->runtimeFeedback()->isConnected);
        $this->assertTrue($adapter->runtimeFeedback()->isAuthenticated);
        $this->assertTrue($adapter->runtimeFeedback()->isLive);
        $this->assertSame('authenticated', $adapter->runtimeFeedback()->connectionState);
        $this->assertSame('active', $adapter->runtimeFeedback()->sessionState);
        $this->assertSame(2, $adapter->runtimeFeedback()->reconnectAttempts);
    }
}
