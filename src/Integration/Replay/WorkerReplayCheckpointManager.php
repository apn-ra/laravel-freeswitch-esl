<?php

namespace ApnTalk\LaravelFreeswitchEsl\Integration\Replay;

use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\ConnectionContext;
use Apntalk\EslReplay\Checkpoint\ReplayCheckpoint;
use Apntalk\EslReplay\Checkpoint\ReplayCheckpointCriteria;
use Apntalk\EslReplay\Checkpoint\ReplayCheckpointReference;
use Apntalk\EslReplay\Checkpoint\ReplayCheckpointRepository;
use Apntalk\EslReplay\Contracts\ReplayArtifactStoreInterface;
use Apntalk\EslReplay\Cursor\ReplayReadCursor;
use Apntalk\EslReplay\Read\ReplayReadCriteria;
use Apntalk\EslReplay\Retention\CheckpointAwarePruner;
use Apntalk\EslReplay\Retention\PrunePolicy;
use Apntalk\EslReplay\Storage\StoredReplayRecord;
use Psr\Log\LoggerInterface;

final class WorkerReplayCheckpointManager
{
    public function __construct(
        private readonly ReplayArtifactStoreInterface $artifactStore,
        private readonly ReplayCheckpointRepository $checkpointRepository,
        private readonly LoggerInterface $logger,
        private readonly bool $enabled = false,
        private readonly ?string $replayStoreDriver = null,
        private readonly ?string $replayStoragePath = null,
        private readonly int $retentionDays = 7,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function resumeState(string $workerName, ConnectionContext $context): array
    {
        $checkpointKey = $this->checkpointKey($workerName, $context);

        if (! $this->enabled) {
            return $this->defaultState($checkpointKey);
        }

        $checkpoint = $this->checkpointRepository->load($checkpointKey);
        $relatedCheckpoint = $checkpoint === null ? null : $this->latestRelatedCheckpoint($checkpoint);
        $recoveryRecord = $relatedCheckpoint === null ? null : $this->nextRecoveryRecord($relatedCheckpoint);

        $reference = $relatedCheckpoint ?? $checkpoint;

        return [
            ...$this->enabledState($checkpointKey),
            'checkpoint_is_resuming' => $checkpoint !== null,
            'checkpoint_last_consumed_sequence' => $checkpoint?->cursor->lastConsumedSequence,
            'checkpoint_saved_at' => $checkpoint?->savedAt?->format(\DateTimeInterface::RFC3339_EXTENDED),
            'checkpoint_metadata' => $checkpoint?->metadata,
            'checkpoint_recovery_supported' => $reference !== null && $this->hasRecoveryAnchors($reference),
            'checkpoint_recovery_reference_key' => $reference?->key,
            'checkpoint_recovery_reference_saved_at' => $reference?->savedAt?->format(\DateTimeInterface::RFC3339_EXTENDED),
            'checkpoint_recovery_candidate_found' => $recoveryRecord !== null,
            'checkpoint_recovery_next_sequence' => $recoveryRecord?->appendSequence,
            'checkpoint_recovery_replay_session_id' => $this->metadataString($reference?->metadata, 'replay_session_id'),
            'checkpoint_recovery_job_uuid' => $this->metadataString($reference?->metadata, 'job_uuid'),
            'checkpoint_recovery_pbx_node_slug' => $this->metadataString($reference?->metadata, 'pbx_node_slug'),
            'checkpoint_recovery_worker_session_id' => $this->metadataString($reference?->metadata, 'worker_session_id'),
        ];
    }

    /**
     * @return array<string, mixed>
     * @param  array<string, mixed>|null  $extraMetadata
     */
    public function save(
        string $workerName,
        ConnectionContext $context,
        string $reason,
        ?array $extraMetadata = null,
    ): array {
        $checkpointKey = $this->checkpointKey($workerName, $context);

        if (! $this->enabled) {
            return array_merge($this->defaultState($checkpointKey), [
                'checkpoint_saved' => false,
                'checkpoint_reason' => $reason,
            ]);
        }

        [$cursor, $lastRecord] = $this->latestCursorForContext($context, $extraMetadata ?? []);
        $replaySessionId = $this->lastReplaySessionId($lastRecord, $extraMetadata ?? []);
        $jobUuid = $this->lastJobUuid($lastRecord, $extraMetadata ?? []);
        $metadata = array_merge([
            'worker_name' => $workerName,
            'provider_code' => $context->providerCode,
            'pbx_node_id' => $context->pbxNodeId,
            'pbx_node_slug' => $context->pbxNodeSlug,
            'connection_profile_id' => $context->connectionProfileId,
            'connection_profile_name' => $context->connectionProfileName,
            'worker_session_id' => $context->workerSessionId,
            'replay_session_id' => $replaySessionId,
            'job_uuid' => $jobUuid,
            'checkpoint_reason' => $reason,
        ], $extraMetadata ?? []);

        $checkpoint = $this->checkpointRepository->save(
            new ReplayCheckpointReference(
                key: $checkpointKey,
                replaySessionId: $replaySessionId,
                jobUuid: $jobUuid,
                pbxNodeSlug: $context->pbxNodeSlug,
                workerSessionId: $context->workerSessionId,
                metadata: $metadata,
            ),
            $cursor,
        );

        $this->logger->info('Replay checkpoint saved for worker runtime', [
            'checkpoint_key' => $checkpointKey,
            'checkpoint_reason' => $reason,
            'checkpoint_last_consumed_sequence' => $cursor->lastConsumedSequence,
            'provider_code' => $context->providerCode,
            'pbx_node_slug' => $context->pbxNodeSlug,
            'worker_session_id' => $context->workerSessionId,
            'replay_session_id' => $replaySessionId,
            'job_uuid' => $jobUuid,
        ]);

        return [
            ...$this->enabledState($checkpointKey),
            'checkpoint_saved' => true,
            'checkpoint_reason' => $reason,
            'checkpoint_last_consumed_sequence' => $cursor->lastConsumedSequence,
            'checkpoint_saved_at' => $checkpoint->savedAt->format(\DateTimeInterface::RFC3339_EXTENDED),
            'checkpoint_metadata' => $checkpoint->metadata,
            'checkpoint_recovery_supported' => $this->hasRecoveryAnchors($checkpoint),
            'checkpoint_recovery_reference_key' => $checkpoint->key,
            'checkpoint_recovery_reference_saved_at' => $checkpoint->savedAt->format(\DateTimeInterface::RFC3339_EXTENDED),
            'checkpoint_recovery_candidate_found' => false,
            'checkpoint_recovery_next_sequence' => null,
            'checkpoint_recovery_replay_session_id' => $replaySessionId,
            'checkpoint_recovery_job_uuid' => $jobUuid,
            'checkpoint_recovery_pbx_node_slug' => $context->pbxNodeSlug,
            'checkpoint_recovery_worker_session_id' => $context->workerSessionId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function historicalSummary(
        string $workerName,
        ConnectionContext $context,
        int $historyLimit = 5,
        ?\DateTimeImmutable $savedFrom = null,
        bool $includeHistory = false,
        ?int $windowHours = null,
    ): array {
        $checkpointKey = $this->checkpointKey($workerName, $context);
        $limit = max(1, min($historyLimit, 50));

        $base = [
            'worker_name' => $workerName,
            'provider_code' => $context->providerCode,
            'pbx_node_slug' => $context->pbxNodeSlug,
            'connection_profile_name' => $context->connectionProfileName,
            'checkpoint_key' => $checkpointKey,
            'checkpoint_enabled' => $this->enabled,
            'latest_checkpoint_saved_at' => null,
            'latest_checkpoint_reason' => null,
            'latest_checkpoint_replay_session_id' => null,
            'latest_checkpoint_worker_session_id' => null,
            'latest_checkpoint_job_uuid' => null,
            'latest_checkpoint_pbx_node_slug' => null,
            'latest_drain_terminal_state' => 'none',
            'latest_drain_started_at' => null,
            'latest_drain_deadline_at' => null,
            'checkpoint_count_in_window' => 0,
            'oldest_checkpoint_saved_at_in_window' => null,
            'newest_checkpoint_saved_at_in_window' => null,
            'historical_pruning_supported' => false,
            'historical_pruning_candidate_count' => null,
            'historical_pruning_window_hours' => $windowHours,
            'historical_pruning_basis' => $this->unsupportedPruningBasis(),
            'history' => [],
        ];

        if (! $this->enabled) {
            return $base;
        }

        $checkpoint = $this->checkpointRepository->load($checkpointKey);

        if ($checkpoint === null) {
            return $base;
        }

        $allRelatedHistory = $this->relatedCheckpointHistory($checkpoint, $limit, null);
        $history = $this->filterCheckpointWindow($allRelatedHistory, $savedFrom);
        $latest = $history[0] ?? $checkpoint;
        $latestMetadata = $latest->metadata;
        $oldestHistoryEntry = $history === [] ? null : $history[array_key_last($history)];
        $pruningSummary = $this->historicalPruningSummary($allRelatedHistory);

        return [
            ...$base,
            'latest_checkpoint_saved_at' => $latest->savedAt->format(\DateTimeInterface::RFC3339_EXTENDED),
            'latest_checkpoint_reason' => $this->metadataString($latestMetadata, 'checkpoint_reason'),
            'latest_checkpoint_replay_session_id' => $this->metadataString($latestMetadata, 'replay_session_id'),
            'latest_checkpoint_worker_session_id' => $this->metadataString($latestMetadata, 'worker_session_id'),
            'latest_checkpoint_job_uuid' => $this->metadataString($latestMetadata, 'job_uuid'),
            'latest_checkpoint_pbx_node_slug' => $this->metadataString($latestMetadata, 'pbx_node_slug'),
            'latest_drain_terminal_state' => $this->drainTerminalState($latest),
            'latest_drain_started_at' => $this->metadataString($latestMetadata, 'drain_started_at'),
            'latest_drain_deadline_at' => $this->metadataString($latestMetadata, 'drain_deadline_at'),
            'checkpoint_count_in_window' => count($history),
            'oldest_checkpoint_saved_at_in_window' => $oldestHistoryEntry?->savedAt->format(\DateTimeInterface::RFC3339_EXTENDED),
            'newest_checkpoint_saved_at_in_window' => $history === []
                ? null
                : $history[0]->savedAt->format(\DateTimeInterface::RFC3339_EXTENDED),
            ...$pruningSummary,
            'history' => $includeHistory ? array_map(
                fn (ReplayCheckpoint $entry): array => $this->historyEntry($entry),
                $history,
            ) : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function historicalRetentionMetadata(?int $windowHours = null): array
    {
        return [
            'historical_retention_supported' => $this->supportsHistoricalPruning(),
            'historical_retention_store_driver' => $this->replayStoreDriver,
            'historical_retention_days' => $this->retentionDays,
            'historical_retention_storage_path_present' => $this->replayStoragePath !== null
                && trim($this->replayStoragePath) !== '',
            'historical_retention_basis' => $this->historicalRetentionBasis(),
            'historical_retention_support_path' => $this->historicalRetentionSupportPath(),
            'historical_retention_support_source' => 'apntalk/esl-replay',
            'historical_retention_window_hours' => $windowHours,
        ];
    }

    private function checkpointKey(string $workerName, ConnectionContext $context): string
    {
        return implode('.', [
            'worker-runtime',
            $workerName,
            $context->providerCode,
            $context->pbxNodeSlug,
            $context->connectionProfileName,
        ]);
    }

    /**
     * @return list<ReplayCheckpoint>
     */
    private function relatedCheckpointHistory(
        ReplayCheckpoint $checkpoint,
        int $limit,
        ?\DateTimeImmutable $savedFrom,
    ): array {
        $criteria = $this->historicalCheckpointCriteria($checkpoint, $limit);

        if ($criteria === null) {
            return $this->filterCheckpointWindow([$checkpoint], $savedFrom);
        }

        try {
            $matches = $this->checkpointRepository->find($criteria);
        } catch (\LogicException $e) {
            $this->logger->warning('Replay checkpoint history lookup skipped because bounded checkpoint queries are unavailable.', [
                'checkpoint_key' => $checkpoint->key,
                'error' => $e->getMessage(),
            ]);

            $matches = [$checkpoint];
        }

        if ($matches === []) {
            $matches = [$checkpoint];
        }

        return $this->filterCheckpointWindow($matches, $savedFrom);
    }

    /**
     * @param  array<string, mixed>  $extraMetadata
     * @return array{0: ReplayReadCursor, 1: StoredReplayRecord|null}
     */
    private function latestCursorForContext(ConnectionContext $context, array $extraMetadata): array
    {
        $cursor = $this->artifactStore->openCursor();
        $lastMatching = $cursor;
        $lastRecord = null;
        $criteria = new ReplayReadCriteria(
            jobUuid: $this->metadataString($extraMetadata, 'job_uuid'),
            replaySessionId: $this->metadataString($extraMetadata, 'replay_session_id'),
            pbxNodeSlug: $context->pbxNodeSlug,
            workerSessionId: $context->workerSessionId,
        );

        while (true) {
            $records = $this->artifactStore->readFromCursor($cursor, 500, $criteria);

            if ($records === []) {
                return [$lastMatching, $lastRecord];
            }

            foreach ($records as $record) {
                $cursor = $cursor->advance($record->appendSequence);

                if (
                    ($record->runtimeFlags['provider_code'] ?? null) !== $context->providerCode
                    || ($record->runtimeFlags['pbx_node_slug'] ?? null) !== $context->pbxNodeSlug
                    || ($record->runtimeFlags['connection_profile_name'] ?? null) !== $context->connectionProfileName
                ) {
                    continue;
                }

                if (($record->runtimeFlags['worker_session_id'] ?? null) !== $context->workerSessionId) {
                    continue;
                }

                $lastMatching = $cursor;
                $lastRecord = $record;
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultState(string $checkpointKey): array
    {
        return [
            'checkpoint_enabled' => false,
            'checkpoint_key' => $checkpointKey,
            'checkpoint_is_resuming' => false,
            'checkpoint_last_consumed_sequence' => null,
            'checkpoint_saved_at' => null,
            'checkpoint_metadata' => null,
            'checkpoint_recovery_supported' => false,
            'checkpoint_recovery_reference_key' => null,
            'checkpoint_recovery_reference_saved_at' => null,
            'checkpoint_recovery_candidate_found' => false,
            'checkpoint_recovery_next_sequence' => null,
            'checkpoint_recovery_replay_session_id' => null,
            'checkpoint_recovery_job_uuid' => null,
            'checkpoint_recovery_pbx_node_slug' => null,
            'checkpoint_recovery_worker_session_id' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function enabledState(string $checkpointKey): array
    {
        return array_merge($this->defaultState($checkpointKey), [
            'checkpoint_enabled' => true,
        ]);
    }

    private function latestRelatedCheckpoint(ReplayCheckpoint $checkpoint): ReplayCheckpoint
    {
        $criteria = $this->checkpointCriteriaForLimit($checkpoint, 1);

        if ($criteria === null) {
            return $checkpoint;
        }

        try {
            return $this->checkpointRepository->find($criteria)[0] ?? $checkpoint;
        } catch (\LogicException $e) {
            $this->logger->warning('Replay checkpoint lookup skipped because bounded checkpoint queries are unavailable.', [
                'checkpoint_key' => $checkpoint->key,
                'error' => $e->getMessage(),
            ]);

            return $checkpoint;
        }
    }

    private function nextRecoveryRecord(ReplayCheckpoint $checkpoint): ?StoredReplayRecord
    {
        $criteria = $this->recoveryCriteria($checkpoint);

        if ($criteria === null) {
            return null;
        }

        return $this->artifactStore->readFromCursor($checkpoint->cursor, 1, $criteria)[0] ?? null;
    }

    private function checkpointCriteria(ReplayCheckpoint $checkpoint): ?ReplayCheckpointCriteria
    {
        return $this->checkpointCriteriaForLimit($checkpoint, 100);
    }

    private function checkpointCriteriaForLimit(ReplayCheckpoint $checkpoint, int $limit): ?ReplayCheckpointCriteria
    {
        $metadata = $checkpoint->metadata;
        $replaySessionId = $this->metadataString($metadata, 'replay_session_id');
        $jobUuid = $this->metadataString($metadata, 'job_uuid');
        $pbxNodeSlug = $this->metadataString($metadata, 'pbx_node_slug');
        $workerSessionId = $this->metadataString($metadata, 'worker_session_id');

        if (
            $replaySessionId === null
            && $jobUuid === null
            && $pbxNodeSlug === null
            && $workerSessionId === null
        ) {
            return null;
        }

        return new ReplayCheckpointCriteria(
            replaySessionId: $replaySessionId,
            jobUuid: $jobUuid,
            pbxNodeSlug: $pbxNodeSlug,
            workerSessionId: $workerSessionId,
            limit: $limit,
        );
    }

    private function recoveryCriteria(ReplayCheckpoint $checkpoint): ?ReplayReadCriteria
    {
        $metadata = $checkpoint->metadata;
        $replaySessionId = $this->metadataString($metadata, 'replay_session_id');
        $jobUuid = $this->metadataString($metadata, 'job_uuid');
        $pbxNodeSlug = $this->metadataString($metadata, 'pbx_node_slug');
        $workerSessionId = $this->metadataString($metadata, 'worker_session_id');

        if (
            $replaySessionId === null
            && $jobUuid === null
            && $pbxNodeSlug === null
            && $workerSessionId === null
        ) {
            return null;
        }

        return new ReplayReadCriteria(
            jobUuid: $jobUuid,
            replaySessionId: $replaySessionId,
            pbxNodeSlug: $pbxNodeSlug,
            workerSessionId: $workerSessionId,
        );
    }

    private function hasRecoveryAnchors(ReplayCheckpoint $checkpoint): bool
    {
        return $this->checkpointCriteria($checkpoint) !== null;
    }

    private function historicalCheckpointCriteria(ReplayCheckpoint $checkpoint, int $limit): ?ReplayCheckpointCriteria
    {
        $metadata = $checkpoint->metadata;
        $replaySessionId = $this->metadataString($metadata, 'replay_session_id');
        $jobUuid = $this->metadataString($metadata, 'job_uuid');
        $pbxNodeSlug = $this->metadataString($metadata, 'pbx_node_slug');

        if ($replaySessionId !== null || $jobUuid !== null || $pbxNodeSlug !== null) {
            return new ReplayCheckpointCriteria(
                replaySessionId: $replaySessionId,
                jobUuid: $jobUuid,
                pbxNodeSlug: $pbxNodeSlug,
                workerSessionId: null,
                limit: $limit,
            );
        }

        return $this->checkpointCriteriaForLimit($checkpoint, $limit);
    }

    /**
     * @return list<ReplayCheckpoint>
     * @param  list<ReplayCheckpoint>  $checkpoints
     */
    private function filterCheckpointWindow(array $checkpoints, ?\DateTimeImmutable $savedFrom): array
    {
        if ($savedFrom === null) {
            return $checkpoints;
        }

        return array_values(array_filter(
            $checkpoints,
            static fn (ReplayCheckpoint $checkpoint): bool => $checkpoint->savedAt >= $savedFrom,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function historyEntry(ReplayCheckpoint $checkpoint): array
    {
        return [
            'checkpoint_key' => $checkpoint->key,
            'saved_at' => $checkpoint->savedAt->format(\DateTimeInterface::RFC3339_EXTENDED),
            'reason' => $this->metadataString($checkpoint->metadata, 'checkpoint_reason'),
            'replay_session_id' => $this->metadataString($checkpoint->metadata, 'replay_session_id'),
            'worker_session_id' => $this->metadataString($checkpoint->metadata, 'worker_session_id'),
            'job_uuid' => $this->metadataString($checkpoint->metadata, 'job_uuid'),
            'pbx_node_slug' => $this->metadataString($checkpoint->metadata, 'pbx_node_slug'),
            'drain_terminal_state' => $this->drainTerminalState($checkpoint),
            'drain_started_at' => $this->metadataString($checkpoint->metadata, 'drain_started_at'),
            'drain_deadline_at' => $this->metadataString($checkpoint->metadata, 'drain_deadline_at'),
        ];
    }

    private function drainTerminalState(ReplayCheckpoint $checkpoint): string
    {
        return match ($this->metadataString($checkpoint->metadata, 'checkpoint_reason')) {
            'drain-completed' => 'completed',
            'drain-timeout' => 'timed_out',
            default => 'none',
        };
    }

    /**
     * @param  list<ReplayCheckpoint>  $checkpoints
     * @return array<string, mixed>
     */
    private function historicalPruningSummary(array $checkpoints): array
    {
        if ($checkpoints === []) {
            return [
                'historical_pruning_supported' => false,
                'historical_pruning_candidate_count' => null,
                'historical_pruning_basis' => $this->unsupportedPruningBasis(),
            ];
        }

        if (! $this->supportsHistoricalPruning()) {
            return [
                'historical_pruning_supported' => false,
                'historical_pruning_candidate_count' => null,
                'historical_pruning_basis' => $this->unsupportedPruningBasis(),
            ];
        }

        try {
            $plan = (new CheckpointAwarePruner($this->replayStoragePath()))->plan(
                $checkpoints,
                $this->prunePolicy(),
            );

            return [
                'historical_pruning_supported' => true,
                'historical_pruning_candidate_count' => $plan->prunedCount,
                'historical_pruning_basis' => 'filesystem_retention_plan',
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('Replay checkpoint pruning posture lookup skipped because a filesystem retention plan could not be derived safely.', [
                'error' => $e->getMessage(),
                'replay_store_driver' => $this->replayStoreDriver,
                'replay_storage_path' => $this->replayStoragePath,
            ]);

            return [
                'historical_pruning_supported' => false,
                'historical_pruning_candidate_count' => null,
                'historical_pruning_basis' => 'filesystem_retention_plan_unavailable',
            ];
        }
    }

    private function supportsHistoricalPruning(): bool
    {
        return in_array($this->replayStoreDriver, ['filesystem', 'file'], true)
            && $this->replayStoragePath !== null
            && trim($this->replayStoragePath) !== ''
            && $this->retentionDays > 0;
    }

    private function unsupportedPruningBasis(): string
    {
        if (! in_array($this->replayStoreDriver, ['filesystem', 'file'], true)) {
            return 'requires_filesystem_replay_store';
        }

        if ($this->replayStoragePath === null || trim($this->replayStoragePath) === '') {
            return 'requires_replay_storage_path';
        }

        if ($this->retentionDays <= 0) {
            return 'retention_days_not_configured';
        }

        return 'historical_pruning_unavailable';
    }

    private function historicalRetentionBasis(): string
    {
        if ($this->supportsHistoricalPruning()) {
            return 'configured_filesystem_retention_policy';
        }

        return $this->unsupportedPruningBasis();
    }

    private function historicalRetentionSupportPath(): ?string
    {
        if ($this->supportsHistoricalPruning()) {
            return 'checkpoint_aware_pruner';
        }

        return null;
    }

    private function replayStoragePath(): string
    {
        if ($this->replayStoragePath === null || trim($this->replayStoragePath) === '') {
            throw new \LogicException('Replay storage path is required for historical pruning posture.');
        }

        return $this->replayStoragePath;
    }

    private function prunePolicy(): PrunePolicy
    {
        return new PrunePolicy(
            maxRecordAge: new \DateInterval(sprintf('P%dD', $this->retentionDays)),
        );
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function metadataString(?array $metadata, string $key): ?string
    {
        $value = $metadata[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $extraMetadata
     */
    private function lastReplaySessionId(?StoredReplayRecord $record, array $extraMetadata): ?string
    {
        return $this->metadataString($extraMetadata, 'replay_session_id')
            ?? $this->metadataString($record?->correlationIds ?? null, 'replay_session_id')
            ?? $this->metadataString($record?->runtimeFlags ?? null, 'replay_session_id');
    }

    /**
     * @param  array<string, mixed>  $extraMetadata
     */
    private function lastJobUuid(?StoredReplayRecord $record, array $extraMetadata): ?string
    {
        return $this->metadataString($extraMetadata, 'job_uuid')
            ?? $record?->jobUuid;
    }
}
