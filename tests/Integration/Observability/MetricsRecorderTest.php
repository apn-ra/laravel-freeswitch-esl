<?php

namespace ApnTalk\LaravelFreeswitchEsl\Tests\Integration\Observability;

use ApnTalk\LaravelFreeswitchEsl\Contracts\HealthReporterInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\MetricsRecorderInterface;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\Models\PbxNode as PbxNodeModel;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\Models\PbxProvider;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\HealthSnapshot;
use ApnTalk\LaravelFreeswitchEsl\Events\MetricsRecorded;
use ApnTalk\LaravelFreeswitchEsl\Tests\Support\Fakes\ArrayLogger;
use ApnTalk\LaravelFreeswitchEsl\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Psr\Log\LoggerInterface;

class MetricsRecorderTest extends TestCase
{
    public function test_log_metrics_driver_records_structured_metrics_for_health_reporting(): void
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

        $logger = new ArrayLogger;
        $app = $this->app;
        self::assertNotNull($app);

        $app->instance(LoggerInterface::class, $logger);
        $app['config']->set('freeswitch-esl.observability.metrics.driver', 'log');
        $app['config']->set('freeswitch-esl.observability.metrics.log_level', 'notice');
        $app->forgetInstance(MetricsRecorderInterface::class);
        $app->forgetInstance(HealthReporterInterface::class);

        /** @var HealthReporterInterface $reporter */
        $reporter = $app->make(HealthReporterInterface::class);

        $reporter->record(new HealthSnapshot(
            pbxNodeId: (int) $nodeModel->getKey(),
            pbxNodeSlug: 'primary-fs',
            providerCode: 'freeswitch',
            status: HealthSnapshot::STATUS_HEALTHY,
            connectionState: 'authenticated',
            subscriptionState: 'active',
            workerAssignmentScope: 'node',
            inflightCount: 3,
            retryAttempt: 1,
            isDraining: false,
            lastHeartbeatAt: new \DateTimeImmutable('2026-04-18T10:00:00+00:00'),
            recentFailures: [],
            meta: [],
            capturedAt: new \DateTimeImmutable('2026-04-18T10:00:00+00:00'),
        ));

        $this->assertCount(2, $logger->records);
        $this->assertSame('notice', $logger->records[0]['level']);
        $this->assertSame('FreeSwitch ESL metric recorded', $logger->records[0]['message']);
        $this->assertSame('freeswitch_esl.health.snapshot_recorded', $logger->records[0]['context']['metric_name']);
        $this->assertSame('counter', $logger->records[0]['context']['metric_type']);
        $this->assertSame(1, $logger->records[0]['context']['metric_value']);
        $this->assertSame([
            'provider_code' => 'freeswitch',
            'pbx_node_slug' => 'primary-fs',
            'status' => 'healthy',
            'live_runtime_linked' => false,
        ], $logger->records[0]['context']['metric_tags']);
        $this->assertSame('freeswitch_esl.health.inflight_count', $logger->records[1]['context']['metric_name']);
        $this->assertSame('gauge', $logger->records[1]['context']['metric_type']);
        $this->assertSame(3, $logger->records[1]['context']['metric_value']);
    }

    public function test_event_metrics_driver_dispatches_laravel_events(): void
    {
        Event::fake([MetricsRecorded::class]);

        $app = $this->app;
        self::assertNotNull($app);

        $app['config']->set('freeswitch-esl.observability.metrics.driver', 'event');
        $app->forgetInstance(MetricsRecorderInterface::class);

        /** @var MetricsRecorderInterface $metrics */
        $metrics = $app->make(MetricsRecorderInterface::class);
        $metrics->increment('freeswitch_esl.worker.boot', tags: [
            'worker_name' => 'ingest-worker',
            'pbx_node_slug' => 'primary-fs',
        ]);
        $metrics->gauge('freeswitch_esl.worker.inflight', 2, [
            'worker_name' => 'ingest-worker',
            'pbx_node_slug' => 'primary-fs',
        ]);

        Event::assertDispatched(MetricsRecorded::class, function (MetricsRecorded $event): bool {
            return $event->name === 'freeswitch_esl.worker.boot'
                && $event->type === 'counter'
                && $event->value === 1
                && $event->tags['pbx_node_slug'] === 'primary-fs';
        });

        Event::assertDispatched(MetricsRecorded::class, function (MetricsRecorded $event): bool {
            return $event->name === 'freeswitch_esl.worker.inflight'
                && $event->type === 'gauge'
                && $event->value === 2
                && $event->schemaVersion === MetricsRecorded::SCHEMA_VERSION;
        });
    }
}
