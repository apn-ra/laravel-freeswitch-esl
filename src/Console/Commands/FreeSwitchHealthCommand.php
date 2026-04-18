<?php

namespace ApnTalk\LaravelFreeswitchEsl\Console\Commands;

use ApnTalk\LaravelFreeswitchEsl\Contracts\HealthReporterInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\PbxRegistryInterface;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\HealthSnapshot;
use ApnTalk\LaravelFreeswitchEsl\Health\HealthSummaryBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Display structured health snapshots for PBX nodes.
 */
class FreeSwitchHealthCommand extends Command
{
    protected $signature = 'freeswitch:health
                            {--pbx=     : Show health for a specific node slug}
                            {--cluster= : Show health for all nodes in a cluster}
                            {--summary  : Include aggregate DB-backed health summary and bounded readiness/liveness posture}
                            {--json     : Output as JSON}';

    protected $description = 'Show health status of PBX nodes';

    public function handle(
        HealthReporterInterface $reporter,
        PbxRegistryInterface $registry,
        HealthSummaryBuilder $summaryBuilder,
        ConfigRepository $config,
    ): int {
        $pbx = $this->stringOption('pbx');
        $cluster = $this->stringOption('cluster');
        $withSummary = $this->booleanOption('summary');
        $asJson = $this->booleanOption('json');

        try {
            $snapshots = match (true) {
                $pbx !== null => [$reporter->forNode($registry->findBySlug($pbx)->id)],
                $cluster !== null => $reporter->forCluster($cluster),
                default => $reporter->forAllActive(),
            };
            $summary = $summaryBuilder->summary($snapshots);

            if ($asJson) {
                $payload = $withSummary
                    ? $summaryBuilder->buildSummaryPayload($snapshots)
                    : array_map(fn ($s) => $s->toArray(), $snapshots);

                $this->line($this->jsonString($payload));

                return self::SUCCESS;
            }

            if (empty($snapshots)) {
                $this->warn('No health data available.');

                if ($withSummary) {
                    $this->line(sprintf(
                        'Aggregate DB-backed health summary: %d node(s); readiness %s; liveness %s.',
                        $summary['node_count'],
                        $summaryBuilder->aggregatePosture($summary),
                        $summaryBuilder->aggregatePosture($summary),
                    ));
                }

                return self::SUCCESS;
            }

            $this->table(
                ['Node', 'Provider', 'Status', 'Connection', 'Last Heartbeat', 'Inflight', 'Draining'],
                array_map(fn ($s) => [
                    $s->pbxNodeSlug,
                    $s->providerCode,
                    $s->status,
                    $s->connectionState,
                    $s->lastHeartbeatAt?->format('Y-m-d H:i:s') ?? 'never',
                    $s->inflightCount,
                    $s->isDraining ? 'yes' : 'no',
                ], $snapshots)
            );

            $this->line(sprintf(
                'Observability posture: metrics driver %s.',
                $this->metricsDriver($config),
            ));
            $this->renderRuntimeLinkedSnapshotFacts($snapshots);
            $this->renderBackpressureFacts($snapshots);

            if ($withSummary) {
                $this->line(sprintf(
                    'Aggregate DB-backed health summary: %d node(s); healthy %d; degraded %d; unhealthy %d; unknown %d; readiness %s; liveness %s.',
                    $summary['node_count'],
                    $summary['healthy_count'],
                    $summary['degraded_count'],
                    $summary['unhealthy_count'],
                    $summary['unknown_count'],
                    $summaryBuilder->aggregatePosture($summary),
                    $summaryBuilder->aggregatePosture($summary),
                ));
            }

            $this->line(
                'Replay-backed recovery posture is not part of the default DB-backed health snapshot. Use worker runtime output for checkpoint/recovery visibility.'
            );

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function booleanOption(string $name): bool
    {
        return $this->option($name) === true;
    }

    /**
     * @param  array<mixed>  $value
     */
    private function jsonString(array $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT) ?: '[]';
    }

    /**
     * @param  list<HealthSnapshot>  $snapshots
     */
    private function renderRuntimeLinkedSnapshotFacts(array $snapshots): void
    {
        $linkedSnapshots = array_values(array_filter(
            $snapshots,
            fn (HealthSnapshot $snapshot): bool => (($snapshot->meta['live_runtime_linked'] ?? false) === true)
        ));

        if ($linkedSnapshots === []) {
            $this->line(
                'Runtime-linked health facts are not present in these stored health snapshots. Use a real worker run to record bounded upstream runtime-status facts when available.'
            );

            return;
        }

        $this->line('Runtime-linked snapshot facts:');

        foreach ($linkedSnapshots as $snapshot) {
            $phase = $this->metaString($snapshot, 'runtime_status_phase') ?? 'unknown';
            $runtimeActive = $this->metaBoolLabel($snapshot, 'runtime_active');
            $recovery = $this->metaBoolLabel($snapshot, 'runtime_recovery_in_progress');
            $lastConnect = $this->metaString($snapshot, 'runtime_last_successful_connect_at') ?? 'not recorded';
            $lastDisconnectAt = $this->metaString($snapshot, 'runtime_last_disconnect_at');
            $lastDisconnectReason = $this->metaString($snapshot, 'runtime_last_disconnect_reason_message') ?? 'not recorded';
            $lastFailureAt = $this->metaString($snapshot, 'runtime_last_failure_at');
            $lastFailure = $this->metaString($snapshot, 'runtime_last_failure_message') ?? 'not recorded';

            $disconnectSummary = $lastDisconnectAt === null
                ? 'not recorded'
                : sprintf('%s (%s)', $lastDisconnectAt, $lastDisconnectReason);

            $failureSummary = $lastFailureAt === null
                ? $lastFailure
                : sprintf('%s (%s)', $lastFailureAt, $lastFailure);

            $this->line(sprintf(
                '  - %s: runtime-linked yes; phase %s; active %s; recovery_in_progress %s; last_successful_connect %s; last_disconnect %s; last_failure %s',
                $snapshot->pbxNodeSlug,
                $phase,
                $runtimeActive,
                $recovery,
                $lastConnect,
                $disconnectSummary,
                $failureSummary,
            ));

            $this->line(sprintf(
                '    Runtime-linked snapshot age: %s',
                $this->snapshotAgeHint($snapshot),
            ));
        }
    }

    private function snapshotAgeHint(HealthSnapshot $snapshot): string
    {
        if ($snapshot->lastHeartbeatAt === null) {
            return 'not available from stored snapshot timestamp';
        }

        $ageSeconds = (int) max(0, CarbonImmutable::now('UTC')->getTimestamp() - $snapshot->lastHeartbeatAt->getTimestamp());
        $hint = $this->formatDuration($ageSeconds);

        if ($ageSeconds > $this->heartbeatTimeoutSeconds()) {
            return sprintf('%s (may be stale)', $hint);
        }

        return $hint;
    }

    private function heartbeatTimeoutSeconds(): int
    {
        $configured = config('freeswitch-esl.health.heartbeat_timeout_seconds');

        return is_int($configured) && $configured > 0 ? $configured : 60;
    }

    private function formatDuration(int $seconds): string
    {
        $minutes = intdiv($seconds, 60);
        $remainingSeconds = $seconds % 60;

        if ($minutes === 0) {
            return sprintf('%ds', $remainingSeconds);
        }

        return sprintf('%dm %ds', $minutes, $remainingSeconds);
    }

    private function metaString(HealthSnapshot $snapshot, string $key): ?string
    {
        $value = $snapshot->meta[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function metaBoolLabel(HealthSnapshot $snapshot, string $key): string
    {
        $value = $snapshot->meta[$key] ?? null;

        return is_bool($value) ? ($value ? 'yes' : 'no') : 'unknown';
    }

    /**
     * @param  list<HealthSnapshot>  $snapshots
     */
    private function renderBackpressureFacts(array $snapshots): void
    {
        $backpressureSnapshots = array_values(array_filter(
            $snapshots,
            fn (HealthSnapshot $snapshot): bool => array_key_exists('backpressure_active', $snapshot->meta)
                || array_key_exists('backpressure_reason', $snapshot->meta)
                || array_key_exists('backpressure_rejected_total', $snapshot->meta)
        ));

        if ($backpressureSnapshots === []) {
            $this->line(
                'Backpressure posture is not present in these stored health snapshots. Use worker runtime output for bounded live rejection posture.'
            );

            return;
        }

        $this->line('Backpressure snapshot facts:');

        foreach ($backpressureSnapshots as $snapshot) {
            $active = $snapshot->meta['backpressure_active'] ?? null;
            $reason = $this->metaString($snapshot, 'backpressure_reason') ?? 'not recorded';
            $rejectedTotal = $snapshot->meta['backpressure_rejected_total'] ?? 'not recorded';
            $maxInflight = $snapshot->meta['max_inflight'] ?? 'not recorded';

            $this->line(sprintf(
                '  - %s: active %s; reason %s; max_inflight %s; rejected_total %s; operator action %s',
                $snapshot->pbxNodeSlug,
                is_bool($active) ? ($active ? 'yes' : 'no') : 'unknown',
                $reason,
                is_scalar($maxInflight) ? (string) $maxInflight : 'not recorded',
                is_scalar($rejectedTotal) ? (string) $rejectedTotal : 'not recorded',
                $this->backpressureAction($snapshot),
            ));
        }
    }

    private function backpressureAction(HealthSnapshot $snapshot): string
    {
        if ($snapshot->isDraining || (($snapshot->meta['runtime_draining'] ?? false) === true)) {
            return 'let drain complete before adding work';
        }

        if (($snapshot->meta['backpressure_active'] ?? false) === true
            && (($snapshot->meta['backpressure_limit_reached'] ?? false) === true)) {
            return 'reduce inflight load or raise max_inflight deliberately';
        }

        if (($snapshot->meta['backpressure_active'] ?? false) === true) {
            return 'wait for the worker posture to recover before adding work';
        }

        return 'none';
    }

    private function metricsDriver(ConfigRepository $config): string
    {
        $driver = $config->get('freeswitch-esl.observability.metrics.driver');

        return is_string($driver) && $driver !== '' ? $driver : 'unknown';
    }
}
