<?php

namespace ApnTalk\LaravelFreeswitchEsl\Console\Commands;

use ApnTalk\LaravelFreeswitchEsl\Contracts\HealthReporterInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\PbxRegistryInterface;
use Illuminate\Console\Command;

/**
 * Display structured health snapshots for PBX nodes.
 */
class FreeSwitchHealthCommand extends Command
{
    protected $signature = 'freeswitch:health
                            {--pbx=     : Show health for a specific node slug}
                            {--cluster= : Show health for all nodes in a cluster}
                            {--json     : Output as JSON}';

    protected $description = 'Show health status of PBX nodes';

    public function handle(HealthReporterInterface $reporter, PbxRegistryInterface $registry): int
    {
        $pbx = $this->stringOption('pbx');
        $cluster = $this->stringOption('cluster');
        $asJson = $this->booleanOption('json');

        try {
            $snapshots = match (true) {
                $pbx !== null     => [$reporter->forNode($registry->findBySlug($pbx)->id)],
                $cluster !== null => $reporter->forCluster($cluster),
                default           => $reporter->forAllActive(),
            };

            if ($asJson) {
                $this->line($this->jsonString(
                    array_map(fn ($s) => $s->toArray(), $snapshots),
                ));

                return self::SUCCESS;
            }

            if (empty($snapshots)) {
                $this->warn('No health data available.');

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
}
