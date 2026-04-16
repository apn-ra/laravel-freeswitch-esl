<?php

namespace ApnTalk\LaravelFreeswitchEsl\Console\Commands;

use ApnTalk\LaravelFreeswitchEsl\Contracts\ConnectionFactoryInterface;
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
 * ---
 * ASSIGNMENT PATHS
 * ---
 *
 * Ephemeral path (CLI flags)
 * --------------------------
 * Pass exactly one targeting flag. An in-memory WorkerAssignment is built at
 * startup and NOT written to the worker_assignments table. The supervisor
 * resolves nodes from the assignment scope and boots runtimes immediately.
 *
 *   --pbx=<slug>       single node by slug
 *   --cluster=<name>   all active nodes in a named cluster
 *   --tag=<name>       all active nodes matching a tag
 *   --provider=<code>  all active nodes for a provider code
 *   --all-active       all currently active PBX nodes
 *
 * DB-backed path (--db)
 * ---------------------
 * Pass --db with no targeting flags. The command looks up active records in
 * the worker_assignments table for the given --worker=<name> and resolves
 * the node set from those records. This is the intended production path:
 * assignment scope is owned by the database, not the CLI invocation.
 *
 * Mixing --db with a targeting flag is an error.
 * Passing --db with a worker name that has no active assignments is an error.
 *
 * ---
 * EXAMPLES
 * ---
 *
 *   # Ephemeral — single node
 *   php artisan freeswitch:worker --pbx=primary-fs
 *
 *   # Ephemeral — cluster
 *   php artisan freeswitch:worker --cluster=us-east
 *
 *   # DB-backed — worker_assignments table lookup
 *   php artisan freeswitch:worker --worker=ingest-worker --db
 */
class FreeSwitchWorkerCommand extends Command
{
    protected $signature = 'freeswitch:worker
                            {--worker=esl-worker : Worker name used for session identity and (with --db) DB assignment lookup}
                            {--db                : Use DB-backed worker_assignments instead of CLI targeting flags}
                            {--pbx=              : [ephemeral] Target a single PBX node by slug}
                            {--cluster=          : [ephemeral] Target all nodes in a named cluster}
                            {--tag=              : [ephemeral] Target all nodes matching a tag}
                            {--provider=         : [ephemeral] Target all nodes for a provider code}
                            {--all-active        : [ephemeral] Target all currently active PBX nodes}';

    protected $description = 'Start a long-lived ESL worker for one or more PBX nodes';

    public function handle(
        PbxRegistryInterface $registry,
        WorkerAssignmentResolverInterface $assignmentResolver,
        ConnectionResolverInterface $connectionResolver,
        ConnectionFactoryInterface $connectionFactory,
        LoggerInterface $logger,
    ): int {
        $workerName = (string) $this->option('worker');
        $useDb = (bool) $this->option('db');

        $supervisor = new WorkerSupervisor(
            assignmentResolver: $assignmentResolver,
            connectionResolver: $connectionResolver,
            connectionFactory: $connectionFactory,
            logger: $logger,
        );

        try {
            if ($useDb) {
                return $this->runDbBacked($workerName, $supervisor, $assignmentResolver);
            }

            return $this->runEphemeral($workerName, $supervisor, $registry);
        } finally {
            $supervisor->shutdown();
        }
    }

    // -------------------------------------------------------------------------
    // Ephemeral path (CLI targeting flags)
    // -------------------------------------------------------------------------

    private function runEphemeral(
        string $workerName,
        WorkerSupervisor $supervisor,
        PbxRegistryInterface $registry,
    ): int {
        $assignment = $this->buildEphemeralAssignment($workerName, $registry);

        if ($assignment === null) {
            $this->error(
                'Provide exactly one targeting flag: --pbx=<slug>, --cluster=<name>, '
                . '--tag=<name>, --provider=<code>, or --all-active. '
                . 'Use --db to load assignment from the worker_assignments table instead.'
            );

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Starting worker [%s] in [%s] mode (ephemeral — not persisted to worker_assignments).',
            $assignment->workerName,
            $assignment->assignmentMode,
        ));

        try {
            $supervisor->run($assignment);
            $this->reportPreparedHandoffs($supervisor);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Worker failed: ' . $e->getMessage());

            return self::FAILURE;
        }
    }

    private function buildEphemeralAssignment(
        string $workerName,
        PbxRegistryInterface $registry,
    ): ?WorkerAssignment {
        $pbx = $this->option('pbx');
        $cluster = $this->option('cluster');
        $tag = $this->option('tag');
        $provider = $this->option('provider');
        $allActive = $this->option('all-active');

        // --db mixed with targeting flags is already caught by runDbBacked guard,
        // so here we just count the explicit targeting options.
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

    // -------------------------------------------------------------------------
    // DB-backed path (worker_assignments table)
    // -------------------------------------------------------------------------

    private function runDbBacked(
        string $workerName,
        WorkerSupervisor $supervisor,
        WorkerAssignmentResolverInterface $assignmentResolver,
    ): int {
        // --db is mutually exclusive with targeting flags.
        $targeting = array_filter([
            $this->option('pbx'),
            $this->option('cluster'),
            $this->option('tag'),
            $this->option('provider'),
            $this->option('all-active') ?: null,
        ]);

        if (! empty($targeting)) {
            $this->error(
                '--db is mutually exclusive with targeting flags (--pbx, --cluster, --tag, --provider, --all-active).'
            );

            return self::FAILURE;
        }

        $nodes = $assignmentResolver->resolveForWorkerName($workerName);

        if (empty($nodes)) {
            $this->error(sprintf(
                'No active worker_assignments found for worker [%s]. '
                . 'Seed the worker_assignments table or use a targeting flag instead.',
                $workerName,
            ));

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Starting worker [%s] from DB assignment (worker_assignments table) — %d node(s).',
            $workerName,
            count($nodes),
        ));

        try {
            $supervisor->runForNodes($workerName, 'db-backed', $nodes);
            $this->reportPreparedHandoffs($supervisor);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Worker failed: ' . $e->getMessage());

            return self::FAILURE;
        }
    }

    private function reportPreparedHandoffs(WorkerSupervisor $supervisor): void
    {
        $statuses = $supervisor->runtimeStatuses();
        $preparedCount = 0;

        foreach ($statuses as $status) {
            if (($status->meta['connection_handoff_prepared'] ?? false) === true) {
                $preparedCount++;
            }
        }

        $this->info(sprintf(
            'Prepared runtime handoff for %d/%d node(s); live apntalk/esl-react runtime not started in this scaffolding pass.',
            $preparedCount,
            count($statuses),
        ));
    }
}
