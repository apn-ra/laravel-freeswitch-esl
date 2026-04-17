<?php

namespace ApnTalk\LaravelFreeswitchEsl\Console\Commands;

use ApnTalk\LaravelFreeswitchEsl\Contracts\HealthReporterInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\PbxRegistryInterface;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\HealthSnapshot;
use Illuminate\Console\Command;

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

    public function handle(HealthReporterInterface $reporter, PbxRegistryInterface $registry): int
    {
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
            $summary = $this->summary($snapshots);

            if ($asJson) {
                $payload = $withSummary
                    ? [
                        'report_surface' => 'health_snapshot_summary',
                        'snapshot_basis' => 'db_health_snapshot',
                        'live_runtime_linked' => false,
                        'summary' => $summary,
                        'liveness_posture' => $this->aggregatePosture($summary),
                        'readiness_posture' => $this->aggregatePosture($summary),
                        'snapshots' => array_map(fn ($s) => $s->toArray(), $snapshots),
                    ]
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
                        $this->aggregatePosture($summary),
                        $this->aggregatePosture($summary),
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

            if ($withSummary) {
                $this->line(sprintf(
                    'Aggregate DB-backed health summary: %d node(s); healthy %d; degraded %d; unhealthy %d; unknown %d; readiness %s; liveness %s.',
                    $summary['node_count'],
                    $summary['healthy_count'],
                    $summary['degraded_count'],
                    $summary['unhealthy_count'],
                    $summary['unknown_count'],
                    $this->aggregatePosture($summary),
                    $this->aggregatePosture($summary),
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
     * @return array<string, int>
     */
    private function summary(array $snapshots): array
    {
        $summary = [
            'node_count' => count($snapshots),
            'healthy_count' => 0,
            'degraded_count' => 0,
            'unhealthy_count' => 0,
            'unknown_count' => 0,
        ];

        foreach ($snapshots as $snapshot) {
            match ($snapshot->status) {
                HealthSnapshot::STATUS_HEALTHY => $summary['healthy_count']++,
                HealthSnapshot::STATUS_DEGRADED => $summary['degraded_count']++,
                HealthSnapshot::STATUS_UNHEALTHY => $summary['unhealthy_count']++,
                default => $summary['unknown_count']++,
            };
        }

        return $summary;
    }

    /**
     * @param  array<string, int>  $summary
     */
    private function aggregatePosture(array $summary): string
    {
        if (($summary['node_count'] ?? 0) === 0) {
            return HealthSnapshot::STATUS_UNKNOWN;
        }

        if (($summary['unhealthy_count'] ?? 0) > 0) {
            return HealthSnapshot::STATUS_UNHEALTHY;
        }

        if ((($summary['degraded_count'] ?? 0) > 0) || (($summary['unknown_count'] ?? 0) > 0)) {
            return HealthSnapshot::STATUS_DEGRADED;
        }

        return HealthSnapshot::STATUS_HEALTHY;
    }
}
