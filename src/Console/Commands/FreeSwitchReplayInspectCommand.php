<?php

namespace ApnTalk\LaravelFreeswitchEsl\Console\Commands;

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
        if (! app()->bound(\ApnTalk\LaravelFreeswitchEsl\Contracts\Upstream\ReplayCaptureStoreInterface::class)) {
            $this->error(
                'ReplayCaptureStoreInterface is not bound in the container. '
                . 'Ensure apntalk/esl-replay is installed and configured.'
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
            : new \DateTimeImmutable();
        $limit = $limitOption !== null ? (int) $limitOption : 50;
        $asJson = $this->booleanOption('json');

        $store = app(\ApnTalk\LaravelFreeswitchEsl\Contracts\Upstream\ReplayCaptureStoreInterface::class);
        $partitionKey = $pbxSlug ?? 'all';
        $envelopes = array_slice($store->retrieve($partitionKey, $from, $to), 0, $limit);

        if ($asJson) {
            $this->line($this->jsonString($envelopes));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Replay store: %d envelope(s) in [%s → %s] for partition [%s]',
            count($envelopes),
            $from->format('Y-m-d H:i:s'),
            $to->format('Y-m-d H:i:s'),
            $partitionKey
        ));

        foreach ($envelopes as $i => $envelope) {
            $this->line(sprintf(
                '[%d] %s',
                $i + 1,
                $this->jsonString($envelope)
            ));
        }

        return self::SUCCESS;
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
     * @param  mixed  $value
     */
    private function jsonString(mixed $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT) ?: '{}';
    }
}
