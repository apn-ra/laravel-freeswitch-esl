<?php

namespace ApnTalk\LaravelFreeswitchEsl\Tests\Replay;

use Apntalk\EslReplay\Checkpoint\ReplayCheckpointRepository;
use Apntalk\EslReplay\Contracts\ReplayCheckpointStoreInterface;
use Apntalk\EslCore\Contracts\ReplayEnvelopeInterface;
use Apntalk\EslReplay\Contracts\ReplayArtifactStoreInterface;
use Apntalk\EslReplay\Checkpoint\ReplayCheckpoint;
use Apntalk\EslReplay\Read\ReplayReadCriteria;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\ConnectionContext;
use ApnTalk\LaravelFreeswitchEsl\Integration\Replay\ReplayArtifactStoreCaptureSink;
use ApnTalk\LaravelFreeswitchEsl\Integration\Replay\ReplayEnvelopeArtifactAdapter;
use ApnTalk\LaravelFreeswitchEsl\Integration\Replay\WorkerReplayCheckpointManager;
use ApnTalk\LaravelFreeswitchEsl\Tests\TestCase;
use Psr\Log\NullLogger;

class ReplayIntegrationTest extends TestCase
{
    private string $storagePath;

    private string $checkpointStoragePath;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $this->storagePath = sys_get_temp_dir() . '/laravel-freeswitch-esl-tests/replay-integration.sqlite';
        $this->checkpointStoragePath = sys_get_temp_dir() . '/laravel-freeswitch-esl-tests/replay-checkpoints';

        $app['config']->set('freeswitch-esl.replay.enabled', true);
        $app['config']->set('freeswitch-esl.replay.store_driver', 'database');
        $app['config']->set('freeswitch-esl.replay.storage_path', $this->storagePath);
        $app['config']->set('freeswitch-esl.replay.checkpoint_storage_path', $this->checkpointStoragePath);
    }

    protected function setUp(): void
    {
        $this->cleanupStorage();

        parent::setUp();
    }

    protected function tearDown(): void
    {
        $this->cleanupStorage();

        parent::tearDown();
    }

    public function test_replay_capture_store_can_be_bound_in_container(): void
    {
        $this->assertTrue($this->app->bound(ReplayArtifactStoreInterface::class));
        $this->assertInstanceOf(
            ReplayArtifactStoreInterface::class,
            $this->app->make(ReplayArtifactStoreInterface::class),
        );
        $this->assertTrue($this->app->bound(ReplayCheckpointStoreInterface::class));
    }

    public function test_capture_envelope_carries_runtime_identity(): void
    {
        $context = $this->makeContext(workerSessionId: 'worker-session-a');
        $adapter = new ReplayEnvelopeArtifactAdapter(
            $this->makeEnvelope(sessionId: 'replay-session-a'),
            $context,
        );

        $recordId = $this->store()->write($adapter);
        $record = $this->store()->readById($recordId);

        $this->assertNotNull($record);
        $this->assertSame('freeswitch', $record->runtimeFlags['provider_code']);
        $this->assertSame(10, $record->runtimeFlags['pbx_node_id']);
        $this->assertSame('node-a', $record->runtimeFlags['pbx_node_slug']);
        $this->assertSame('worker-session-a', $record->runtimeFlags['worker_session_id']);
        $this->assertSame('primary', $record->runtimeFlags['connection_profile_name']);
        $this->assertSame('replay-session-a', $record->sessionId);
    }

    public function test_retrieve_returns_envelopes_within_time_window(): void
    {
        $store = $this->store();

        $store->write(new ReplayEnvelopeArtifactAdapter(
            $this->makeEnvelope(
                sessionId: 'replay-session-old',
                capturedAtMicros: 1_700_000_000_000_000,
            ),
            $this->makeContext(),
        ));
        $store->write(new ReplayEnvelopeArtifactAdapter(
            $this->makeEnvelope(
                sessionId: 'replay-session-new',
                capturedAtMicros: 1_800_000_000_000_000,
            ),
            $this->makeContext(workerSessionId: 'worker-session-b'),
        ));

        $records = $store->readFromCursor(
            $store->openCursor(),
            10,
            new ReplayReadCriteria(
                capturedFrom: new \DateTimeImmutable('@1750000000'),
                capturedUntil: new \DateTimeImmutable('@1850000000'),
            ),
        );

        $this->assertCount(1, $records);
        $this->assertSame('replay-session-new', $records[0]->sessionId);
        $this->assertSame('worker-session-b', $records[0]->runtimeFlags['worker_session_id']);
    }

    public function test_partitioning_by_pbx_node_slug_is_enforced(): void
    {
        $sinkA = new ReplayArtifactStoreCaptureSink($this->store(), $this->makeContext(pbxNodeSlug: 'node-a'), new NullLogger());
        $sinkB = new ReplayArtifactStoreCaptureSink($this->store(), $this->makeContext(pbxNodeSlug: 'node-b'), new NullLogger());

        $sinkA->capture($this->makeEnvelope(sessionId: 'replay-session-a'));
        $sinkB->capture($this->makeEnvelope(sessionId: 'replay-session-b'));

        $all = $this->store()->readFromCursor($this->store()->openCursor(), 10);
        $nodeA = array_values(array_filter(
            $all,
            static fn ($record): bool => ($record->runtimeFlags['pbx_node_slug'] ?? null) === 'node-a',
        ));
        $nodeB = array_values(array_filter(
            $all,
            static fn ($record): bool => ($record->runtimeFlags['pbx_node_slug'] ?? null) === 'node-b',
        ));

        $this->assertCount(1, $nodeA);
        $this->assertCount(1, $nodeB);
        $this->assertSame('replay-session-a', $nodeA[0]->sessionId);
        $this->assertSame('replay-session-b', $nodeB[0]->sessionId);
    }

    public function test_replay_inspect_command_exits_gracefully_when_disabled(): void
    {
        $this->app['config']->set('freeswitch-esl.replay.enabled', false);

        $this->artisan('freeswitch:replay:inspect')
            ->expectsOutput('Replay capture is disabled. Set freeswitch-esl.replay.enabled = true to enable.')
            ->assertExitCode(0);
    }

    public function test_replay_inspect_command_reads_stored_records_for_one_pbx(): void
    {
        $sinkA = new ReplayArtifactStoreCaptureSink($this->store(), $this->makeContext(pbxNodeSlug: 'node-a'), new NullLogger());
        $sinkB = new ReplayArtifactStoreCaptureSink($this->store(), $this->makeContext(pbxNodeSlug: 'node-b'), new NullLogger());

        $sinkA->capture($this->makeEnvelope(
            sessionId: 'replay-session-a',
            capturedAtMicros: 1_800_000_000_000_000,
        ));
        $sinkB->capture($this->makeEnvelope(
            sessionId: 'replay-session-b',
            capturedAtMicros: 1_800_000_100_000_000,
        ));

        $this->artisan('freeswitch:replay:inspect', [
            '--pbx' => 'node-a',
            '--from' => '2027-01-15T07:59:59+00:00',
            '--to' => '2027-01-15T08:00:01+00:00',
            '--json' => true,
        ])
            ->expectsOutputToContain('"pbx_node_slug": "node-a"')
            ->doesntExpectOutputToContain('"pbx_node_slug": "node-b"')
            ->assertExitCode(0);
    }

    public function test_worker_checkpoint_manager_saves_checkpoint_using_replay_runtime_identity(): void
    {
        $sink = new ReplayArtifactStoreCaptureSink(
            $this->store(),
            $this->makeContext(workerSessionId: 'worker-session-a'),
            new NullLogger(),
        );
        $sink->capture($this->makeEnvelope(sessionId: 'replay-session-a'));

        $manager = new WorkerReplayCheckpointManager(
            artifactStore: $this->store(),
            checkpointRepository: new ReplayCheckpointRepository($this->app->make(ReplayCheckpointStoreInterface::class)),
            logger: new NullLogger(),
            enabled: true,
        );

        $result = $manager->save(
            'ingest-worker',
            $this->makeContext(workerSessionId: 'worker-session-a'),
            'drain-requested',
        );
        /** @var ReplayCheckpointStoreInterface $checkpointStore */
        $checkpointStore = $this->app->make(ReplayCheckpointStoreInterface::class);
        $checkpoint = $checkpointStore->load('worker-runtime.ingest-worker.freeswitch.node-a.primary');

        $this->assertTrue($result['checkpoint_saved']);
        $this->assertSame(1, $result['checkpoint_last_consumed_sequence']);
        $this->assertInstanceOf(ReplayCheckpoint::class, $checkpoint);
        $this->assertSame(1, $checkpoint->cursor->lastConsumedSequence);
        $this->assertSame('worker-session-a', $checkpoint->metadata['worker_session_id']);
        $this->assertSame('node-a', $checkpoint->metadata['pbx_node_slug']);
        $this->assertSame('replay-session-a', $checkpoint->metadata['replay_session_id']);
        $this->assertSame('drain-requested', $checkpoint->metadata['checkpoint_reason']);
    }

    public function test_worker_checkpoint_manager_surfaces_bounded_recovery_candidate_from_checkpoint_identity(): void
    {
        $context = $this->makeContext(workerSessionId: 'worker-session-a');
        $sink = new ReplayArtifactStoreCaptureSink($this->store(), $context, new NullLogger());
        $sink->capture($this->makeEnvelope(
            sessionId: 'replay-session-a',
            capturedAtMicros: 1_800_000_000_000_000,
        ));

        $manager = new WorkerReplayCheckpointManager(
            artifactStore: $this->store(),
            checkpointRepository: new ReplayCheckpointRepository($this->app->make(ReplayCheckpointStoreInterface::class)),
            logger: new NullLogger(),
            enabled: true,
        );

        $manager->save('ingest-worker', $context, 'drain-requested');

        $sink->capture($this->makeEnvelope(
            sessionId: 'replay-session-a',
            capturedAtMicros: 1_800_000_100_000_000,
        ));

        $resume = $manager->resumeState('ingest-worker', $context->withWorkerSession('worker-session-b'));

        $this->assertTrue($resume['checkpoint_enabled']);
        $this->assertTrue($resume['checkpoint_is_resuming']);
        $this->assertTrue($resume['checkpoint_recovery_supported']);
        $this->assertTrue($resume['checkpoint_recovery_candidate_found']);
        $this->assertSame(2, $resume['checkpoint_recovery_next_sequence']);
        $this->assertSame('replay-session-a', $resume['checkpoint_recovery_replay_session_id']);
        $this->assertSame('worker-session-a', $resume['checkpoint_recovery_worker_session_id']);
        $this->assertSame('node-a', $resume['checkpoint_recovery_pbx_node_slug']);
    }

    private function store(): ReplayArtifactStoreInterface
    {
        return $this->app->make(ReplayArtifactStoreInterface::class);
    }

    private function cleanupStorage(): void
    {
        if (isset($this->storagePath) && file_exists($this->storagePath)) {
            @unlink($this->storagePath);
        }

        if (isset($this->checkpointStoragePath) && is_dir($this->checkpointStoragePath)) {
            $files = glob($this->checkpointStoragePath . '/*.json');

            if (is_array($files)) {
                foreach ($files as $file) {
                    @unlink($file);
                }
            }

            @rmdir($this->checkpointStoragePath);
        }
    }

    private function makeContext(
        string $pbxNodeSlug = 'node-a',
        ?string $workerSessionId = 'worker-session',
    ): ConnectionContext {
        return new ConnectionContext(
            pbxNodeId: 10,
            pbxNodeSlug: $pbxNodeSlug,
            providerCode: 'freeswitch',
            host: '127.0.0.1',
            port: 8021,
            username: 'admin',
            resolvedPassword: 'secret',
            transport: 'tcp',
            connectionProfileId: 20,
            connectionProfileName: 'primary',
            workerSessionId: $workerSessionId,
        );
    }

    private function makeEnvelope(
        string $sessionId,
        int $capturedAtMicros = 1_800_000_000_000_000,
    ): ReplayEnvelopeInterface {
        return new class ($sessionId, $capturedAtMicros) implements ReplayEnvelopeInterface {
            public function __construct(
                private readonly string $sessionId,
                private readonly int $capturedAtMicros,
            ) {}

            public function capturedType(): string
            {
                return 'event';
            }

            public function capturedName(): string
            {
                return 'CHANNEL_CREATE';
            }

            public function sessionId(): ?string
            {
                return $this->sessionId;
            }

            public function captureSequence(): int
            {
                return 1;
            }

            public function capturedAtMicros(): int
            {
                return $this->capturedAtMicros;
            }

            public function protocolSequence(): ?string
            {
                return '42';
            }

            public function rawPayload(): string
            {
                return 'Event-Name: CHANNEL_CREATE';
            }

            public function classifierContext(): array
            {
                return ['content-type' => 'text/event-plain'];
            }

            public function protocolFacts(): array
            {
                return [
                    'event-name' => 'CHANNEL_CREATE',
                    'job-uuid' => 'job-123',
                ];
            }

            public function derivedMetadata(): array
            {
                return [
                    'replay-artifact-version' => '1',
                    'replay-artifact-name' => 'event.raw',
                    'runtime-capture-path' => 'event.raw',
                    'runtime-connection-generation' => '7',
                ];
            }
        };
    }
}
