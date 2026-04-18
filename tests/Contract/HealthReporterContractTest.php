<?php

namespace ApnTalk\LaravelFreeswitchEsl\Tests\Contract;

use ApnTalk\LaravelFreeswitchEsl\Contracts\HealthReporterInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\MetricsRecorderInterface;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\Models\PbxNode;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\Models\PbxProvider;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\HealthSnapshot;
use ApnTalk\LaravelFreeswitchEsl\Tests\Support\Fakes\ArrayMetricsRecorder;
use ApnTalk\LaravelFreeswitchEsl\Tests\TestCase;
use Illuminate\Support\Carbon;

class HealthReporterContractTest extends TestCase
{
    public function test_for_all_active_and_for_cluster_return_health_snapshots_only(): void
    {
        self::assertNotNull($this->app);

        $provider = $this->createProvider();
        $this->createNode($provider, ['slug' => 'east-a', 'cluster' => 'east', 'health_status' => 'healthy']);
        $this->createNode($provider, ['slug' => 'east-b', 'cluster' => 'east', 'health_status' => 'healthy']);
        $this->createNode($provider, ['slug' => 'west-a', 'cluster' => 'west', 'health_status' => 'unknown']);

        /** @var HealthReporterInterface $reporter */
        $reporter = $this->app->make(HealthReporterInterface::class);

        $all = $reporter->forAllActive();
        $east = $reporter->forCluster('east');

        $this->assertNotEmpty($all);
        $this->assertContainsOnlyInstancesOf(HealthSnapshot::class, $all);
        $this->assertContainsOnlyInstancesOf(HealthSnapshot::class, $east);
        $this->assertSame(['east-a', 'east-b'], array_values(array_map(
            fn (HealthSnapshot $snapshot) => $snapshot->pbxNodeSlug,
            $east,
        )));
    }

    public function test_record_persists_machine_usable_snapshot_and_emits_metrics(): void
    {
        self::assertNotNull($this->app);
        Carbon::setTestNow('2026-04-18T12:00:00+00:00');

        $provider = $this->createProvider();
        $node = $this->createNode($provider, [
            'slug' => 'primary-fs',
            'health_status' => 'unknown',
        ]);

        $metrics = new ArrayMetricsRecorder;
        $this->app->instance(MetricsRecorderInterface::class, $metrics);

        /** @var HealthReporterInterface $reporter */
        $reporter = $this->app->make(HealthReporterInterface::class);
        $reporter->record(new HealthSnapshot(
            pbxNodeId: (int) $node->getKey(),
            pbxNodeSlug: 'primary-fs',
            providerCode: 'freeswitch',
            status: HealthSnapshot::STATUS_HEALTHY,
            connectionState: 'authenticated',
            subscriptionState: 'active',
            workerAssignmentScope: 'node',
            inflightCount: 4,
            retryAttempt: 1,
            isDraining: false,
            lastHeartbeatAt: new \DateTimeImmutable('2026-04-18T11:59:55+00:00'),
            recentFailures: [],
            meta: [
                'snapshot_basis' => 'worker_runtime_status_snapshot',
                'live_runtime_linked' => true,
                'runtime_truth_source' => 'apntalk/esl-react-runtime-status-snapshot',
                'runtime_connection_state' => 'authenticated',
                'runtime_session_state' => 'active',
                'runtime_reconnect_attempts' => 1,
                'runtime_draining' => false,
            ],
        ));

        $recorded = $reporter->forNode((int) $node->getKey());

        $this->assertSame(HealthSnapshot::STATUS_HEALTHY, $recorded->status);
        $this->assertSame('authenticated', $recorded->connectionState);
        $this->assertSame('active', $recorded->subscriptionState);
        $this->assertSame(4, $recorded->inflightCount);
        $this->assertSame(1, $recorded->retryAttempt);
        $this->assertTrue($recorded->meta['live_runtime_linked']);
        $this->assertSame('apntalk/esl-react-runtime-status-snapshot', $recorded->meta['runtime_truth_source']);
        $this->assertCount(1, $metrics->increments);
        $this->assertCount(1, $metrics->gauges);

        Carbon::setTestNow();
    }

    private function createProvider(string $code = 'freeswitch'): PbxProvider
    {
        return PbxProvider::query()->create([
            'code' => $code,
            'name' => ucfirst($code),
            'driver_class' => 'test-driver',
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createNode(PbxProvider $provider, array $overrides = []): PbxNode
    {
        return PbxNode::query()->create(array_merge([
            'provider_id' => (int) $provider->getKey(),
            'name' => 'Primary FS',
            'slug' => 'primary-fs',
            'host' => '127.0.0.1',
            'port' => 8021,
            'username' => '',
            'password_secret_ref' => 'secret',
            'transport' => 'tcp',
            'is_active' => true,
            'health_status' => 'healthy',
        ], $overrides));
    }
}
