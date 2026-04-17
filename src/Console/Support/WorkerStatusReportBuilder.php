<?php

namespace ApnTalk\LaravelFreeswitchEsl\Console\Support;

use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\WorkerStatus;

final class WorkerStatusReportBuilder
{
    /**
     * @param  array<string, WorkerStatus>  $statuses
     * @return array<string, mixed>
     */
    public function workerReport(string $workerName, string $assignmentMode, array $statuses): array
    {
        return [
            'worker_name' => $workerName,
            'assignment_mode' => $assignmentMode,
            'summary' => $this->statusSummary($statuses),
            'nodes' => array_map(
                fn (string $slug, WorkerStatus $status): array => $this->machineReadableNodeStatus($slug, $status),
                array_keys($statuses),
                array_values($statuses),
            ),
        ];
    }

    /**
     * @param  array<string, WorkerStatus>  $statuses
     * @return array<string, int>
     */
    public function statusSummary(array $statuses): array
    {
        $preparedCount = 0;
        $runnerInvokedCount = 0;
        $pushObservedCount = 0;
        $runtimeObservedCount = 0;

        foreach ($statuses as $status) {
            if (($status->meta['connection_handoff_prepared'] ?? false) === true) {
                $preparedCount++;
            }

            if (($status->meta['runtime_runner_invoked'] ?? false) === true) {
                $runnerInvokedCount++;
            }

            if ($status->isRuntimePushObserved()) {
                $pushObservedCount++;
            }

            if ($status->isRuntimeLoopActive()) {
                $runtimeObservedCount++;
            }
        }

        return [
            'node_count' => count($statuses),
            'prepared_count' => $preparedCount,
            'runtime_runner_invoked_count' => $runnerInvokedCount,
            'push_lifecycle_observed_count' => $pushObservedCount,
            'live_runtime_observed_count' => $runtimeObservedCount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function machineReadableNodeStatus(string $slug, WorkerStatus $status): array
    {
        return [
            'pbx_node_slug' => $slug,
            'worker_runtime_state' => $status->state,
            'checkpoint_enabled' => ($status->meta['checkpoint_enabled'] ?? false) === true,
            'checkpoint_key' => $this->metaString($status->meta, 'checkpoint_key'),
            'checkpoint_saved_at' => $this->metaString($status->meta, 'checkpoint_saved_at'),
            'checkpoint_reason' => $this->metaString($status->meta, 'checkpoint_reason')
                ?? $this->metaString($status->meta['checkpoint_metadata'] ?? null, 'checkpoint_reason'),
            'checkpoint_prior_observed' => ($status->meta['checkpoint_is_resuming'] ?? false) === true,
            'checkpoint_recovery_supported' => ($status->meta['checkpoint_recovery_supported'] ?? false) === true,
            'checkpoint_recovery_candidate_found' => ($status->meta['checkpoint_recovery_candidate_found'] ?? false) === true,
            'checkpoint_recovery_next_sequence' => is_scalar($status->meta['checkpoint_recovery_next_sequence'] ?? null)
                ? (int) $status->meta['checkpoint_recovery_next_sequence']
                : null,
            'checkpoint_recovery_replay_session_id' => $this->metaString($status->meta, 'checkpoint_recovery_replay_session_id'),
            'checkpoint_recovery_worker_session_id' => $this->metaString($status->meta, 'checkpoint_recovery_worker_session_id'),
            'checkpoint_recovery_job_uuid' => $this->metaString($status->meta, 'checkpoint_recovery_job_uuid'),
            'checkpoint_recovery_pbx_node_slug' => $this->metaString($status->meta, 'checkpoint_recovery_pbx_node_slug'),
            'drain_requested' => $this->metaString($status->meta, 'drain_started_at') !== null,
            'drain_completed' => ($status->meta['drain_completed'] ?? false) === true,
            'drain_timed_out' => ($status->meta['drain_timed_out'] ?? false) === true,
            'drain_started_at' => $this->metaString($status->meta, 'drain_started_at'),
            'drain_deadline_at' => $this->metaString($status->meta, 'drain_deadline_at'),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    private function metaString(?array $meta, string $key): ?string
    {
        $value = $meta[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
