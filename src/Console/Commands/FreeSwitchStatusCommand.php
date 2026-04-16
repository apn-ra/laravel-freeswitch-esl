<?php

namespace ApnTalk\LaravelFreeswitchEsl\Console\Commands;

use ApnTalk\LaravelFreeswitchEsl\Contracts\PbxRegistryInterface;
use Illuminate\Console\Command;

/**
 * Display the current status of registered PBX nodes.
 */
class FreeSwitchStatusCommand extends Command
{
    protected $signature = 'freeswitch:status
                            {--pbx=     : Filter by node slug}
                            {--cluster= : Filter by cluster}
                            {--provider=: Filter by provider code}';

    protected $description = 'Show status of PBX nodes in the control plane';

    public function handle(PbxRegistryInterface $registry): int
    {
        $slug = $this->option('pbx');
        $cluster = $this->option('cluster');
        $provider = $this->option('provider');

        try {
            $nodes = match (true) {
                $slug !== null    => [$registry->findBySlug($slug)],
                $cluster !== null => $registry->allByCluster($cluster),
                $provider !== null => $registry->allByProvider($provider),
                default           => $registry->allActive(),
            };

            if (empty($nodes)) {
                $this->warn('No PBX nodes matched the given criteria.');

                return self::SUCCESS;
            }

            $this->table(
                ['ID', 'Slug', 'Provider', 'Host', 'Port', 'Cluster', 'Region', 'Health', 'Active'],
                array_map(fn ($node) => [
                    $node->id,
                    $node->slug,
                    $node->providerCode,
                    $node->host,
                    $node->port,
                    $node->cluster ?? '-',
                    $node->region ?? '-',
                    $node->healthStatus,
                    $node->isActive ? 'yes' : 'no',
                ], $nodes)
            );

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
