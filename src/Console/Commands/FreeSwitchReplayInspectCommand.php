<?php

namespace ApnTalk\LaravelFreeswitchEsl\Console\Commands;

use Apntalk\EslReplay\Contracts\ReplayArtifactStoreInterface;
use Apntalk\EslReplay\Read\ReplayReadCriteria;
use Apntalk\EslReplay\Storage\StoredReplayRecord;
use Illuminate\Console\Command;

/**
 * Inspect replay capture metadata and stored envelopes.
 *
 * This command is the Laravel-facing inspection surface for replay data
 * captured via the apntalk/esl-replay integration.
 *
 * Replay store is only available when:
 *   - freeswitch-esl.replay.enabled = true
 *   - apntalk/esl-replay is installed and the store is bound in the container
 *
 * If replay is not enabled or the package is not available, this command
 * reports the status and exits cleanly.
 */
class FreeSwitchReplayInspectCommand extends Command
{
    protected $signature = 'freeswitch:replay:inspect
                            {--pbx=   : Filter by PBX node slug}
                            {--from=  : From datetime (ISO8601, default: 1 hour ago)}
                            {--to=    : To datetime (ISO8601, default: now)}
                            {--limit= : Maximum envelopes to show (default: 50)}
                            {--json   : Output as JSON}';

    protected $description = 'Inspect replay capture store for ESL events (requires freeswitch-esl.replay.enabled)';

    public function handle(): int
    {
        $replayEnabled = config('freeswitch-esl.replay.enabled', false);

        if (! $replayEnabled) {
            $this->warn('Replay capture is disabled. Set freeswitch-esl.replay.enabled = true to enable.');

            return self::SUCCESS;
        }

        // Check if the replay store binding is available
        if (! app()->bound(ReplayArtifactStoreInterface::class)) {
            $this->error(
                'ReplayArtifactStoreInterface is not bound in the container. '
                .'Ensure apntalk/esl-replay is installed and configured.'
            );

            return self::FAILURE;
        }

        $pbxSlug = $this->stringOption('pbx');
        $fromOption = $this->stringOption('from');
        $toOption = $this->stringOption('to');
        $limitOption = $this->stringOption('limit');
        $from = $fromOption !== null
            ? new \DateTimeImmutable($fromOption)
            : new \DateTimeImmutable('-1 hour');
        $to = $toOption !== null
            ? new \DateTimeImmutable($toOption)
            : new \DateTimeImmutable;
        $limit = $limitOption !== null ? (int) $limitOption : 50;
        $asJson = $this->booleanOption('json');

        /** @var ReplayArtifactStoreInterface $store */
        $store = app(ReplayArtifactStoreInterface::class);
        $records = $this->readRecords($store, $from, $to, $pbxSlug, $limit);

        if ($asJson) {
            $this->line($this->jsonString(array_map(
                fn (StoredReplayRecord $record): array => $this->recordToArray($record),
                $records,
            )));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Replay store: %d record(s) in [%s → %s] for PBX [%s]',
            count($records),
            $from->format('Y-m-d H:i:s'),
            $to->format('Y-m-d H:i:s'),
            $pbxSlug ?? 'all'
        ));

        foreach ($records as $i => $record) {
            $runtimeFlags = $record->runtimeFlags;
            $this->line(sprintf(
                '[%d] %s %s session=%s pbx=%s worker=%s',
                $i + 1,
                $record->captureTimestamp->format(\DateTimeInterface::RFC3339_EXTENDED),
                $record->artifactName,
                $record->sessionId ?? '-',
                (string) ($runtimeFlags['pbx_node_slug'] ?? '-'),
                (string) ($runtimeFlags['worker_session_id'] ?? '-'),
            ));
        }

        return self::SUCCESS;
    }

    /**
     * @return list<StoredReplayRecord>
     */
    private function readRecords(
        ReplayArtifactStoreInterface $store,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?string $pbxSlug,
        int $limit,
    ): array {
        $criteria = new ReplayReadCriteria(
            capturedFrom: $from,
            capturedUntil: $to,
        );

        $cursor = $store->openCursor();
        $records = [];
        $batchSize = max(50, min(500, $limit * 4));

        while (count($records) < $limit) {
            $batch = $store->readFromCursor($cursor, $batchSize, $criteria);

            if ($batch === []) {
                break;
            }

            foreach ($batch as $record) {
                $cursor = $cursor->advance($record->appendSequence);

                if ($pbxSlug !== null && ($record->runtimeFlags['pbx_node_slug'] ?? null) !== $pbxSlug) {
                    continue;
                }

                $records[] = $record;

                if (count($records) >= $limit) {
                    break 2;
                }
            }
        }

        return $records;
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

    private function jsonString(mixed $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT) ?: '{}';
    }

    /**
     * @return array<string, mixed>
     */
    private function recordToArray(StoredReplayRecord $record): array
    {
        return [
            'id' => $record->id->value,
            'artifact_version' => $record->artifactVersion,
            'artifact_name' => $record->artifactName,
            'capture_timestamp' => $record->captureTimestamp->format(\DateTimeInterface::RFC3339_EXTENDED),
            'stored_at' => $record->storedAt->format(\DateTimeInterface::RFC3339_EXTENDED),
            'append_sequence' => $record->appendSequence,
            'connection_generation' => $record->connectionGeneration,
            'session_id' => $record->sessionId,
            'job_uuid' => $record->jobUuid,
            'event_name' => $record->eventName,
            'capture_path' => $record->capturePath,
            'correlation_ids' => $record->correlationIds,
            'runtime_flags' => $record->runtimeFlags,
            'payload' => $record->payload,
        ];
    }
}
