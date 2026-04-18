<?php

namespace ApnTalk\LaravelFreeswitchEsl\Tests\Integration\Health;

use ApnTalk\LaravelFreeswitchEsl\Contracts\HealthReporterInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\MetricsRecorderInterface;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\Models\PbxNode as PbxNodeModel;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\Models\PbxProvider;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\HealthSnapshot;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\PbxNode;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\WorkerStatus;
use ApnTalk\LaravelFreeswitchEsl\Tests\Support\Fakes\ArrayMetricsRecorder;
use ApnTalk\LaravelFreeswitchEsl\Tests\TestCase;

class HealthReporterTest extends TestCase
{
    public function test_record_persists_runtime_linked_snapshot_metadata_into_db_backed_health_snapshots(): void
    {
        $provider = PbxProvider::query()->create([
            'code' => 'freeswitch',
            'name' => 'FreeSWITCH',
            'driver_class' => 'test-driver',
            'is_active' => true,
        ]);

        $nodeModel = PbxNodeModel::query()->create([
            'provider_id' => $provider->id,
            'name' => 'Primary FS',
            'slug' => 'primary-fs',
            'host' => '127.0.0.1',
            'port' => 8021,
            'username' => '',
            'password_secret_ref' => 'secret',
            'transport' => 'tcp',
            'is_active' => true,
            'health_status' => 'unknown',
        ]);

        /** @var HealthReporterInterface $reporter */
        $metrics = new ArrayMetricsRecorder;
        $this->app->instance(MetricsRecorderInterface::class, $metrics);
        $reporter = $this->app->make(HealthReporterInterface::class);

        $snapshot = HealthSnapshot::fromWorkerStatus(
            PbxNode::fromRecord([
                ...$nodeModel->toArray(),
                'provider_code' => 'freeswitch',
            ]),
            new WorkerStatus(
                sessionId: 'worker-session-1',
                workerName: 'ingest-worker',
                state: WorkerStatus::STATE_RUNNING,
                assignedNodeSlugs: ['primary-fs'],
                inflightCount: 3,
                retryAttempt: 2,
                isDraining: false,
                lastHeartbeatAt: new \DateTimeImmutable('2026-04-18T10:00:00+00:00'),
                bootedAt: new \DateTimeImmutable('2026-04-18T09:59:00+00:00'),
                meta: [
                    'runtime_feedback_source' => 'apntalk/esl-react-runtime-status-snapshot',
                    'runtime_status_phase' => 'active',
                    'runtime_active' => true,
                    'runtime_recovery_in_progress' => false,
                    'runtime_connection_state' => 'authenticated',
                    'runtime_session_state' => 'active',
                    'runtime_authenticated' => true,
                    'runtime_reconnect_attempts' => 2,
                    'runtime_last_heartbeat_at' => '2026-04-18T10:00:00.000+00:00',
                    'runtime_last_successful_connect_at' => '2026-04-18T09:58:30.000+00:00',
                    'runtime_last_disconnect_at' => '2026-04-18T09:57:00.000+00:00',
                    'runtime_last_disconnect_reason_class' => \RuntimeException::class,
                    'runtime_last_disconnect_reason_message' => 'disconnect observed',
                    'runtime_last_failure_at' => '2026-04-18T09:57:10.000+00:00',
                    'runtime_last_error_class' => \LogicException::class,
                    'runtime_last_error_message' => 'failure summary',
                    'runtime_draining' => false,
                ],
            ),
            'node',
        );

        $reporter->record($snapshot);

        $recorded = $reporter->forNode($nodeModel->id);
        $freshNode = $nodeModel->fresh();

        $this->assertSame(HealthSnapshot::STATUS_HEALTHY, $recorded->status);
        $this->assertSame('authenticated', $recorded->connectionState);
        $this->assertSame('active', $recorded->subscriptionState);
        $this->assertSame('node', $recorded->workerAssignmentScope);
        $this->assertSame(3, $recorded->inflightCount);
        $this->assertSame(2, $recorded->retryAttempt);
        $this->assertTrue($recorded->meta['live_runtime_linked']);
        $this->assertSame('apntalk/esl-react-runtime-status-snapshot', $recorded->meta['runtime_truth_source']);
        $this->assertSame('active', $recorded->meta['runtime_status_phase']);
        $this->assertTrue($recorded->meta['runtime_active']);
        $this->assertSame('disconnect observed', $recorded->meta['runtime_last_disconnect_reason_message']);
        $this->assertSame('failure summary', $recorded->meta['runtime_last_failure_message']);
        $this->assertNotEmpty($recorded->recentFailures);
        $this->assertIsArray($freshNode?->settings_json);
        $this->assertArrayHasKey(HealthSnapshot::META_RUNTIME_SNAPSHOT_KEY, $freshNode->settings_json);
        $this->assertCount(1, $metrics->increments);
        $this->assertSame('freeswitch_esl.health.snapshot_recorded', $metrics->increments[0]['name']);
        $this->assertCount(1, $metrics->gauges);
        $this->assertSame('freeswitch_esl.health.inflight_count', $metrics->gauges[0]['name']);
    }

    public function test_record_overwrites_latest_runtime_linked_snapshot_when_runtime_recovers(): void
    {
        $provider = PbxProvider::query()->create([
            'code' => 'freeswitch',
            'name' => 'FreeSWITCH',
            'driver_class' => 'test-driver',
            'is_active' => true,
        ]);

        $nodeModel = PbxNodeModel::query()->create([
            'provider_id' => $provider->id,
            'name' => 'Primary FS',
            'slug' => 'primary-fs',
            'host' => '127.0.0.1',
            'port' => 8021,
            'username' => '',
            'password_secret_ref' => 'secret',
            'transport' => 'tcp',
            'is_active' => true,
            'health_status' => 'unknown',
        ]);

        /** @var HealthReporterInterface $reporter */
        $reporter = $this->app->make(HealthReporterInterface::class);
        $node = PbxNode::fromRecord([
            ...$nodeModel->toArray(),
            'provider_code' => 'freeswitch',
        ]);

        $reporter->record(HealthSnapshot::fromWorkerStatus(
            $node,
            new WorkerStatus(
                sessionId: 'worker-session-1',
                workerName: 'ingest-worker',
                state: WorkerStatus::STATE_RECONNECTING,
                assignedNodeSlugs: ['primary-fs'],
                inflightCount: 0,
                retryAttempt: 4,
                isDraining: false,
                lastHeartbeatAt: new \DateTimeImmutable('2026-04-18T10:00:00+00:00'),
                bootedAt: new \DateTimeImmutable('2026-04-18T09:59:00+00:00'),
                meta: [
                    'runtime_feedback_source' => 'apntalk/esl-react-runtime-status-snapshot',
                    'runtime_status_phase' => 'reconnecting',
                    'runtime_active' => true,
                    'runtime_recovery_in_progress' => true,
                    'runtime_connection_state' => 'reconnecting',
                    'runtime_session_state' => 'disconnected',
                    'runtime_authenticated' => false,
                    'runtime_reconnect_attempts' => 4,
                    'runtime_last_heartbeat_at' => '2026-04-18T10:00:00.000+00:00',
                    'runtime_last_successful_connect_at' => '2026-04-18T09:58:30.000+00:00',
                    'runtime_last_disconnect_at' => '2026-04-18T09:59:45.000+00:00',
                    'runtime_last_disconnect_reason_class' => \RuntimeException::class,
                    'runtime_last_disconnect_reason_message' => 'link dropped',
                    'runtime_draining' => false,
                ],
            ),
            'node',
        ));

        $reporter->record(HealthSnapshot::fromWorkerStatus(
            $node,
            new WorkerStatus(
                sessionId: 'worker-session-1',
                workerName: 'ingest-worker',
                state: WorkerStatus::STATE_RUNNING,
                assignedNodeSlugs: ['primary-fs'],
                inflightCount: 1,
                retryAttempt: 0,
                isDraining: false,
                lastHeartbeatAt: new \DateTimeImmutable('2026-04-18T10:01:00+00:00'),
                bootedAt: new \DateTimeImmutable('2026-04-18T09:59:00+00:00'),
                meta: [
                    'runtime_feedback_source' => 'apntalk/esl-react-runtime-status-snapshot',
                    'runtime_status_phase' => 'active',
                    'runtime_active' => true,
                    'runtime_recovery_in_progress' => false,
                    'runtime_connection_state' => 'authenticated',
                    'runtime_session_state' => 'active',
                    'runtime_authenticated' => true,
                    'runtime_reconnect_attempts' => 0,
                    'runtime_last_heartbeat_at' => '2026-04-18T10:01:00.000+00:00',
                    'runtime_last_successful_connect_at' => '2026-04-18T10:00:55.000+00:00',
                    'runtime_last_disconnect_at' => '2026-04-18T09:59:45.000+00:00',
                    'runtime_last_disconnect_reason_class' => \RuntimeException::class,
                    'runtime_last_disconnect_reason_message' => 'link dropped',
                    'runtime_draining' => false,
                ],
            ),
            'node',
        ));

        $recorded = $reporter->forNode($nodeModel->id);

        $this->assertSame(HealthSnapshot::STATUS_HEALTHY, $recorded->status);
        $this->assertSame('authenticated', $recorded->connectionState);
        $this->assertSame('active', $recorded->subscriptionState);
        $this->assertSame(1, $recorded->inflightCount);
        $this->assertSame(0, $recorded->retryAttempt);
        $this->assertTrue($recorded->meta['live_runtime_linked']);
        $this->assertSame('active', $recorded->meta['runtime_status_phase']);
        $this->assertFalse($recorded->meta['runtime_recovery_in_progress']);
        $this->assertSame('2026-04-18T10:00:55.000+00:00', $recorded->meta['runtime_last_successful_connect_at']);
        $this->assertSame('link dropped', $recorded->meta['runtime_last_disconnect_reason_message']);
    }
}
