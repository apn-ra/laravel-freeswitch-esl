<?php

namespace ApnTalk\LaravelFreeswitchEsl\Http\Controllers;

use ApnTalk\LaravelFreeswitchEsl\Contracts\HealthReporterInterface;
use ApnTalk\LaravelFreeswitchEsl\Health\HealthSummaryBuilder;
use Illuminate\Http\JsonResponse;

final class HealthSnapshotController
{
    public function __construct(
        private readonly HealthReporterInterface $healthReporter,
        private readonly HealthSummaryBuilder $summaryBuilder,
    ) {}

    public function summary(): JsonResponse
    {
        $payload = $this->summaryBuilder->buildSummaryPayload($this->healthReporter->forAllActive());

        return response()->json($payload);
    }

    public function liveness(): JsonResponse
    {
        $snapshots = $this->healthReporter->forAllActive();
        $summary = $this->summaryBuilder->summary($snapshots);

        return response()->json([
            'report_surface' => 'health_liveness_posture',
            'snapshot_basis' => 'db_health_snapshot',
            'live_runtime_linked' => $this->summaryBuilder->isRuntimeLinked($snapshots),
            'liveness_posture' => $this->summaryBuilder->aggregatePosture($summary),
            'summary' => $summary,
        ]);
    }

    public function readiness(): JsonResponse
    {
        $snapshots = $this->healthReporter->forAllActive();
        $summary = $this->summaryBuilder->summary($snapshots);

        return response()->json([
            'report_surface' => 'health_readiness_posture',
            'snapshot_basis' => 'db_health_snapshot',
            'live_runtime_linked' => $this->summaryBuilder->isRuntimeLinked($snapshots),
            'readiness_posture' => $this->summaryBuilder->aggregatePosture($summary),
            'summary' => $summary,
        ]);
    }
}
