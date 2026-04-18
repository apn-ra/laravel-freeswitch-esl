<?php

namespace ApnTalk\LaravelFreeswitchEsl\Health;

use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\HealthSnapshot;

final class HealthSummaryBuilder
{
    /**
     * @param  list<HealthSnapshot>  $snapshots
     * @return array<string, mixed>
     */
    public function buildSummaryPayload(array $snapshots): array
    {
        $summary = $this->summary($snapshots);

        return [
            'report_surface' => 'health_snapshot_summary',
            'snapshot_basis' => 'db_health_snapshot',
            'live_runtime_linked' => $this->isRuntimeLinked($snapshots),
            'summary' => $summary,
            'liveness_posture' => $this->aggregatePosture($summary),
            'readiness_posture' => $this->aggregatePosture($summary),
            'snapshots' => array_map(fn (HealthSnapshot $snapshot) => $snapshot->toArray(), $snapshots),
        ];
    }

    /**
     * @param  list<HealthSnapshot>  $snapshots
     * @return array<string, int>
     */
    public function summary(array $snapshots): array
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
    public function aggregatePosture(array $summary): string
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

    /**
     * @param  list<HealthSnapshot>  $snapshots
     */
    public function isRuntimeLinked(array $snapshots): bool
    {
        foreach ($snapshots as $snapshot) {
            if (($snapshot->meta['live_runtime_linked'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }
}
