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
use Apntalk\EslReplay\Storage\StoredReplayRecord;
use Psr\Log\LoggerInterface;

final class WorkerReplayCheckpointManager
{
    public function __construct(
        private readonly ReplayArtifactStoreInterface $artifactStore,
        private readonly ReplayCheckpointRepository $checkpointRepository,
        private readonly LoggerInterface $logger,
        private readonly bool $enabled = false,
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
        $criteria = $this->checkpointCriteria($checkpoint);

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
            limit: 1,
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
