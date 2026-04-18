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
        $checkpointEnabled = ($status->meta['checkpoint_enabled'] ?? false) === true;
        $checkpointPriorObserved = ($status->meta['checkpoint_is_resuming'] ?? false) === true;
        $checkpointRecoverySupported = ($status->meta['checkpoint_recovery_supported'] ?? false) === true;
        $checkpointRecoveryCandidateFound = ($status->meta['checkpoint_recovery_candidate_found'] ?? false) === true;

        return [
            'pbx_node_slug' => $slug,
            'worker_runtime_state' => $status->state,
            'runtime_status_phase' => $this->metaString($status->meta, 'runtime_status_phase'),
            'runtime_active' => $this->metaBool($status->meta, 'runtime_active'),
            'runtime_recovery_in_progress' => $this->metaBool($status->meta, 'runtime_recovery_in_progress'),
            'runtime_connection_state' => $this->metaString($status->meta, 'runtime_connection_state'),
            'runtime_session_state' => $this->metaString($status->meta, 'runtime_session_state'),
            'runtime_authenticated' => $this->metaBool($status->meta, 'runtime_authenticated'),
            'runtime_reconnect_attempts' => $this->metaInt($status->meta, 'runtime_reconnect_attempts'),
            'runtime_last_heartbeat_at' => $this->metaString($status->meta, 'runtime_last_heartbeat_at'),
            'runtime_last_successful_connect_at' => $this->metaString($status->meta, 'runtime_last_successful_connect_at'),
            'runtime_last_disconnect_at' => $this->metaString($status->meta, 'runtime_last_disconnect_at'),
            'runtime_last_disconnect_reason_class' => $this->metaString($status->meta, 'runtime_last_disconnect_reason_class'),
            'runtime_last_disconnect_reason_message' => $this->metaString($status->meta, 'runtime_last_disconnect_reason_message'),
            'runtime_last_failure_at' => $this->metaString($status->meta, 'runtime_last_failure_at'),
            'runtime_last_failure_class' => $this->metaString($status->meta, 'runtime_last_error_class'),
            'runtime_last_failure_message' => $this->metaString($status->meta, 'runtime_last_error_message'),
            'max_inflight' => $this->metaInt($status->meta, 'max_inflight'),
            'backpressure_active' => $this->metaBool($status->meta, 'backpressure_active'),
            'backpressure_limit_reached' => $this->metaBool($status->meta, 'backpressure_limit_reached'),
            'backpressure_reason' => $this->metaString($status->meta, 'backpressure_reason'),
            'backpressure_rejected_total' => $this->metaInt($status->meta, 'backpressure_rejected_total'),
            'backpressure_last_rejected_at' => $this->metaString($status->meta, 'backpressure_last_rejected_at'),
            'checkpoint_enabled' => $checkpointEnabled,
            'checkpoint_key' => $this->metaString($status->meta, 'checkpoint_key'),
            'checkpoint_saved_at' => $this->metaString($status->meta, 'checkpoint_saved_at'),
            'checkpoint_reason' => $this->metaString($status->meta, 'checkpoint_reason')
                ?? $this->metaString($status->meta['checkpoint_metadata'] ?? null, 'checkpoint_reason'),
            'checkpoint_prior_observed' => $checkpointPriorObserved,
            'checkpoint_recovery_supported' => $checkpointRecoverySupported,
            'checkpoint_recovery_candidate_found' => $checkpointRecoveryCandidateFound,
            'checkpoint_recovery_next_sequence' => is_scalar($status->meta['checkpoint_recovery_next_sequence'] ?? null)
                ? (int) $status->meta['checkpoint_recovery_next_sequence']
                : null,
            'checkpoint_recovery_replay_session_id' => $this->metaString($status->meta, 'checkpoint_recovery_replay_session_id'),
            'checkpoint_recovery_worker_session_id' => $this->metaString($status->meta, 'checkpoint_recovery_worker_session_id'),
            'checkpoint_recovery_job_uuid' => $this->metaString($status->meta, 'checkpoint_recovery_job_uuid'),
            'checkpoint_recovery_pbx_node_slug' => $this->metaString($status->meta, 'checkpoint_recovery_pbx_node_slug'),
            'resume_supported' => $checkpointEnabled,
            'resume_execution_supported' => false,
            'resume_posture_basis' => $this->resumePostureBasis(
                $checkpointEnabled,
                $checkpointPriorObserved,
                $checkpointRecoverySupported,
            ),
            'resume_checkpoint_available' => $checkpointPriorObserved,
            'resume_candidate_available' => $checkpointRecoveryCandidateFound,
            'resume_candidate_sequence' => is_scalar($status->meta['checkpoint_recovery_next_sequence'] ?? null)
                ? (int) $status->meta['checkpoint_recovery_next_sequence']
                : null,
            'resume_candidate_replay_session_id' => $this->metaString($status->meta, 'checkpoint_recovery_replay_session_id'),
            'resume_candidate_worker_session_id' => $this->metaString($status->meta, 'checkpoint_recovery_worker_session_id'),
            'resume_candidate_job_uuid' => $this->metaString($status->meta, 'checkpoint_recovery_job_uuid'),
            'resume_candidate_pbx_node_slug' => $this->metaString($status->meta, 'checkpoint_recovery_pbx_node_slug'),
            'resume_posture_source' => 'worker_replay_checkpoint_manager',
            'resume_execution_deferred' => true,
            'drain_requested' => $this->metaString($status->meta, 'drain_started_at') !== null,
            'drain_completed' => ($status->meta['drain_completed'] ?? false) === true,
            'drain_timed_out' => ($status->meta['drain_timed_out'] ?? false) === true,
            'drain_started_at' => $this->metaString($status->meta, 'drain_started_at'),
            'drain_deadline_at' => $this->metaString($status->meta, 'drain_deadline_at'),
        ];
    }

    private function resumePostureBasis(
        bool $checkpointEnabled,
        bool $checkpointPriorObserved,
        bool $checkpointRecoverySupported,
    ): string {
        if (! $checkpointEnabled) {
            return 'checkpointing_disabled';
        }

        if (! $checkpointPriorObserved) {
            return 'no_prior_checkpoint';
        }

        if (! $checkpointRecoverySupported) {
            return 'checkpoint_without_recovery_anchors';
        }

        return 'checkpoint_recovery_metadata';
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    private function metaString(?array $meta, string $key): ?string
    {
        $value = $meta[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    private function metaBool(?array $meta, string $key): ?bool
    {
        $value = $meta[$key] ?? null;

        return is_bool($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    private function metaInt(?array $meta, string $key): ?int
    {
        $value = $meta[$key] ?? null;

        return is_int($value) ? $value : null;
    }
}
