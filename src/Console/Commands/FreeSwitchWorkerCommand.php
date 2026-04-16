<?php

namespace ApnTalk\LaravelFreeswitchEsl\Console\Commands;

use ApnTalk\LaravelFreeswitchEsl\Contracts\ConnectionResolverInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\PbxRegistryInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\WorkerAssignmentResolverInterface;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\WorkerAssignment;
use ApnTalk\LaravelFreeswitchEsl\Exceptions\PbxNotFoundException;
use ApnTalk\LaravelFreeswitchEsl\Worker\WorkerSupervisor;
use Illuminate\Console\Command;
use Psr\Log\LoggerInterface;

/**
 * Bootstrap a long-lived ESL worker process.
 *
 * Assignment mode is determined by which targeting option is provided:
 *   --pbx=<slug>       → node mode (single node by slug)
 *   --cluster=<name>   → cluster mode (all active nodes in cluster)
 *   --tag=<name>       → tag mode (all active nodes with tag)
 *   --provider=<code>  → provider mode (all active nodes for provider)
 *   --all-active       → all-active mode (all active nodes)
 *
 * Exactly one targeting option must be provided.
 */
class FreeSwitchWorkerCommand extends Command
{
    protected $signature = 'freeswitch:worker
                            {--worker=esl-worker : Worker name for identity and assignment lookup}
                            {--pbx=              : Target a single PBX node by slug}
                            {--cluster=          : Target all nodes in a cluster}
                            {--tag=              : Target all nodes matching a tag}
                            {--provider=         : Target all nodes for a provider code}
                            {--all-active        : Target all currently active PBX nodes}';

    protected $description = 'Start a long-lived ESL worker for one or more PBX nodes';

    public function handle(
        PbxRegistryInterface $registry,
        WorkerAssignmentResolverInterface $assignmentResolver,
        ConnectionResolverInterface $connectionResolver,
        LoggerInterface $logger,
    ): int {
        $workerName = (string) $this->option('worker');
        $assignment = $this->buildAssignment($workerName, $registry);

        if ($assignment === null) {
            $this->error(
                'Provide exactly one targeting option: --pbx=<slug>, --cluster=<name>, '
                . '--tag=<name>, --provider=<code>, or --all-active.'
            );

            return self::FAILURE;
        }

        $supervisor = new WorkerSupervisor(
            assignmentResolver: $assignmentResolver,
            connectionResolver: $connectionResolver,
            logger: $logger,
        );

        try {
            $this->info(sprintf(
                'Starting worker [%s] in [%s] mode...',
                $assignment->workerName,
                $assignment->assignmentMode
            ));

            $supervisor->run($assignment);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Worker failed: ' . $e->getMessage());

            return self::FAILURE;
        } finally {
            $supervisor->shutdown();
        }
    }

    private function buildAssignment(string $workerName, PbxRegistryInterface $registry): ?WorkerAssignment
    {
        $pbx = $this->option('pbx');
        $cluster = $this->option('cluster');
        $tag = $this->option('tag');
        $provider = $this->option('provider');
        $allActive = $this->option('all-active');

        $given = array_filter([$pbx, $cluster, $tag, $provider, $allActive ?: null]);

        if (count($given) !== 1) {
            return null;
        }

        if ($pbx !== null) {
            try {
                $node = $registry->findBySlug((string) $pbx);
            } catch (PbxNotFoundException $e) {
                $this->error($e->getMessage());

                return null;
            }

            return WorkerAssignment::forNode($workerName, $node->id);
        }

        if ($cluster !== null) {
            return WorkerAssignment::forCluster($workerName, (string) $cluster);
        }

        if ($tag !== null) {
            return WorkerAssignment::forTag($workerName, (string) $tag);
        }

        if ($provider !== null) {
            return WorkerAssignment::forProvider($workerName, (string) $provider);
        }

        if ($allActive) {
            return WorkerAssignment::allActive($workerName);
        }

        return null;
    }
}
