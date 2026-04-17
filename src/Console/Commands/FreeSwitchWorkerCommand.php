<?php

namespace ApnTalk\LaravelFreeswitchEsl\Console\Commands;

use ApnTalk\LaravelFreeswitchEsl\Contracts\ConnectionFactoryInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\ConnectionResolverInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\PbxRegistryInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\RuntimeRunnerInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\WorkerAssignmentResolverInterface;
use ApnTalk\LaravelFreeswitchEsl\Console\Support\WorkerStatusReportBuilder;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\WorkerAssignment;
use ApnTalk\LaravelFreeswitchEsl\Exceptions\PbxNotFoundException;
use ApnTalk\LaravelFreeswitchEsl\Integration\Replay\WorkerReplayCheckpointManager;
use ApnTalk\LaravelFreeswitchEsl\Worker\WorkerSupervisor;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
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
                            {--all-active        : [ephemeral] Target all currently active PBX nodes}
                            {--json              : Emit machine-readable worker recovery/status output}';

    protected $description = 'Start a long-lived ESL worker for one or more PBX nodes';

    public function handle(
        PbxRegistryInterface $registry,
        WorkerAssignmentResolverInterface $assignmentResolver,
        ConnectionResolverInterface $connectionResolver,
        ConnectionFactoryInterface $connectionFactory,
        RuntimeRunnerInterface $runtimeRunner,
        LoggerInterface $logger,
        WorkerReplayCheckpointManager $checkpointManager,
        ConfigRepository $config,
    ): int {
        $workerName = $this->stringOption('worker') ?? 'esl-worker';
        $useDb = $this->booleanOption('db');

        $supervisor = new WorkerSupervisor(
            assignmentResolver: $assignmentResolver,
            connectionResolver: $connectionResolver,
            connectionFactory: $connectionFactory,
            runtimeRunner: $runtimeRunner,
            logger: $logger,
            checkpointManager: $checkpointManager,
            drainTimeoutMilliseconds: (int) $config->get('freeswitch-esl.drain_defaults.timeout_ms', 30000),
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

        if (! $this->booleanOption('json')) {
            $this->info(sprintf(
                'Starting worker [%s] in [%s] mode (ephemeral — not persisted to worker_assignments).',
                $assignment->workerName,
                $assignment->assignmentMode,
            ));
        }

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
        $pbx = $this->stringOption('pbx');
        $cluster = $this->stringOption('cluster');
        $tag = $this->stringOption('tag');
        $provider = $this->stringOption('provider');
        $allActive = $this->booleanOption('all-active');

        // --db mixed with targeting flags is already caught by runDbBacked guard,
        // so here we just count the explicit targeting options.
        $given = array_filter([$pbx, $cluster, $tag, $provider, $allActive ?: null]);

        if (count($given) !== 1) {
            return null;
        }

        if ($pbx !== null) {
            try {
                $node = $registry->findBySlug($pbx);
            } catch (PbxNotFoundException $e) {
                $this->error($e->getMessage());

                return null;
            }

            return WorkerAssignment::forNode($workerName, $node->id);
        }

        if ($cluster !== null) {
            return WorkerAssignment::forCluster($workerName, $cluster);
        }

        if ($tag !== null) {
            return WorkerAssignment::forTag($workerName, $tag);
        }

        if ($provider !== null) {
            return WorkerAssignment::forProvider($workerName, $provider);
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
            $this->stringOption('pbx'),
            $this->stringOption('cluster'),
            $this->stringOption('tag'),
            $this->stringOption('provider'),
            $this->booleanOption('all-active') ? 'all-active' : null,
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

        if (! $this->booleanOption('json')) {
            $this->info(sprintf(
                'Starting worker [%s] from DB assignment (worker_assignments table) — %d node(s).',
                $workerName,
                count($nodes),
            ));
        }

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
        $reportBuilder = new WorkerStatusReportBuilder();
        $summary = $reportBuilder->statusSummary($statuses);

        if ($this->booleanOption('json')) {
            $report = $reportBuilder->workerReport(
                workerName: $this->stringOption('worker') ?? 'esl-worker',
                assignmentMode: $this->booleanOption('db') ? 'db-backed' : 'ephemeral',
                statuses: $statuses,
            );

            $this->line($this->jsonString([
                'worker_name' => $report['worker_name'],
                'recovery_surface' => 'replay_checkpoint_posture',
                'live_recovery_supported' => false,
                'summary' => $report['summary'],
                'nodes' => $report['nodes'],
            ]));

            return;
        }

        $this->info(sprintf(
            'Prepared runtime handoff for %d/%d node(s); runtime runner invoked for %d/%d node(s); push lifecycle observed for %d/%d node(s); live runtime observed for %d/%d node(s).',
            $summary['prepared_count'],
            $summary['node_count'],
            $summary['runtime_runner_invoked_count'],
            $summary['node_count'],
            $summary['push_lifecycle_observed_count'],
            $summary['node_count'],
            $summary['live_runtime_observed_count'],
            $summary['node_count'],
        ));

        if ($statuses === []) {
            return;
        }

        $this->line(
            'Replay-backed checkpoint/recovery posture reflects persisted replay artifacts only; it does not imply live socket or reconnect recovery.'
        );

        foreach ($statuses as $slug => $status) {
            $this->line(sprintf(
                '- %s: checkpoint %s; prior checkpoint %s; recovery hint %s; anchors %s; drain %s',
                $slug,
                $this->checkpointVisibility($status),
                ($status->meta['checkpoint_is_resuming'] ?? false) === true ? 'yes' : 'no',
                $this->recoveryHint($status),
                $this->recoveryAnchors($status),
                $this->drainPosture($status),
            ));
        }
    }

    private function checkpointVisibility(\ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\WorkerStatus $status): string
    {
        if (($status->meta['checkpoint_enabled'] ?? false) !== true) {
            return 'disabled';
        }

        $scope = $this->metaString($status->meta, 'checkpoint_key') ?? 'unknown-scope';
        $reason = $this->metaString($status->meta, 'checkpoint_reason')
            ?? $this->metaString($status->meta['checkpoint_metadata'] ?? null, 'checkpoint_reason')
            ?? 'none';
        $savedAt = $this->metaString($status->meta, 'checkpoint_saved_at') ?? 'none';

        return sprintf('scope=%s, reason=%s, saved_at=%s', $scope, $reason, $savedAt);
    }

    private function recoveryHint(\ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\WorkerStatus $status): string
    {
        if (($status->meta['checkpoint_enabled'] ?? false) !== true) {
            return 'disabled';
        }

        if (($status->meta['checkpoint_recovery_supported'] ?? false) !== true) {
            return 'not-anchored';
        }

        if (($status->meta['checkpoint_recovery_candidate_found'] ?? false) === true) {
            $sequence = $status->meta['checkpoint_recovery_next_sequence'] ?? null;

            return sprintf('candidate-after-sequence-%s', is_scalar($sequence) ? (string) $sequence : 'unknown');
        }

        return 'bounded-check-no-candidate';
    }

    private function recoveryAnchors(\ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\WorkerStatus $status): string
    {
        if (($status->meta['checkpoint_enabled'] ?? false) !== true) {
            return '-';
        }

        $anchors = array_filter([
            'replay=' . ($this->metaString($status->meta, 'checkpoint_recovery_replay_session_id') ?? ''),
            'worker=' . ($this->metaString($status->meta, 'checkpoint_recovery_worker_session_id') ?? ''),
            'job=' . ($this->metaString($status->meta, 'checkpoint_recovery_job_uuid') ?? ''),
            'pbx=' . ($this->metaString($status->meta, 'checkpoint_recovery_pbx_node_slug') ?? ''),
        ], static fn (string $value): bool => ! str_ends_with($value, '='));

        return $anchors === [] ? '-' : implode(', ', $anchors);
    }

    private function drainPosture(\ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\WorkerStatus $status): string
    {
        if (($status->meta['drain_timed_out'] ?? false) === true) {
            return 'timed-out';
        }

        if (($status->meta['drain_completed'] ?? false) === true) {
            return 'completed';
        }

        if ($this->metaString($status->meta, 'drain_started_at') !== null) {
            return 'requested';
        }

        return 'idle';
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
     * @param  array<string, mixed>  $value
     */
    private function jsonString(array $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT) ?: '{}';
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
}
