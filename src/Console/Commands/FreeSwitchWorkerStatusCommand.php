<?php

namespace ApnTalk\LaravelFreeswitchEsl\Console\Commands;

use ApnTalk\LaravelFreeswitchEsl\Console\Support\WorkerStatusReportBuilder;
use ApnTalk\LaravelFreeswitchEsl\Contracts\ConnectionFactoryInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\ConnectionResolverInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\PbxRegistryInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\RuntimeRunnerInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\WorkerAssignmentResolverInterface;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\WorkerAssignment;
use ApnTalk\LaravelFreeswitchEsl\Exceptions\PbxNotFoundException;
use ApnTalk\LaravelFreeswitchEsl\Integration\Replay\WorkerReplayCheckpointManager;
use ApnTalk\LaravelFreeswitchEsl\Worker\WorkerSupervisor;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Psr\Log\LoggerInterface;

class FreeSwitchWorkerStatusCommand extends Command
{
    protected $signature = 'freeswitch:worker:status
                            {--worker=*          : Worker name(s) used for reporting and, with --db, DB assignment lookup}
                            {--db                : Use DB-backed worker_assignments instead of CLI targeting flags}
                            {--pbx=              : [ephemeral] Target a single PBX node by slug}
                            {--cluster=          : [ephemeral] Target all nodes in a named cluster}
                            {--tag=              : [ephemeral] Target all nodes matching a tag}
                            {--provider=         : [ephemeral] Target all nodes for a provider code}
                            {--all-active        : [ephemeral] Target all currently active PBX nodes}';

    protected $description = 'Report machine-readable worker runtime status for one or more worker scopes';

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
        $supervisorFactory = fn (): WorkerSupervisor => new WorkerSupervisor(
            assignmentResolver: $assignmentResolver,
            connectionResolver: $connectionResolver,
            connectionFactory: $connectionFactory,
            runtimeRunner: $runtimeRunner,
            logger: $logger,
            checkpointManager: $checkpointManager,
            drainTimeoutMilliseconds: (int) $config->get('freeswitch-esl.drain_defaults.timeout_ms', 30000),
        );

        try {
            $reports = $this->booleanOption('db')
                ? $this->dbBackedReports($assignmentResolver, $supervisorFactory)
                : [$this->ephemeralReport($registry, $supervisorFactory)];

            $this->line($this->jsonString([
                'report_surface' => 'worker_runtime_status',
                'live_recovery_supported' => false,
                'workers' => $reports,
            ]));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function ephemeralReport(
        PbxRegistryInterface $registry,
        \Closure $supervisorFactory,
    ): array {
        $workerNames = $this->workerNames();

        if (count($workerNames) > 1) {
            throw new \InvalidArgumentException(
                'Multiple --worker values are only supported with --db on freeswitch:worker:status.'
            );
        }

        $workerName = $workerNames[0] ?? 'esl-worker';
        $assignment = $this->buildEphemeralAssignment($workerName, $registry);

        if ($assignment === null) {
            throw new \InvalidArgumentException(
                'Provide exactly one targeting flag: --pbx=<slug>, --cluster=<name>, --tag=<name>, --provider=<code>, or --all-active. Use --db to load worker_assignments scopes instead.'
            );
        }

        $supervisor = $supervisorFactory();

        try {
            $supervisor->prepare($assignment);

            return (new WorkerStatusReportBuilder())->workerReport(
                workerName: $assignment->workerName,
                assignmentMode: $assignment->assignmentMode,
                statuses: $supervisor->runtimeStatuses(),
            );
        } finally {
            $supervisor->shutdown();
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function dbBackedReports(
        WorkerAssignmentResolverInterface $assignmentResolver,
        \Closure $supervisorFactory,
    ): array {
        $reports = [];

        foreach ($this->workerNames() as $workerName) {
            $nodes = $assignmentResolver->resolveForWorkerName($workerName);

            if ($nodes === []) {
                $reports[] = [
                    'worker_name' => $workerName,
                    'assignment_mode' => 'db-backed',
                    'summary' => (new WorkerStatusReportBuilder())->statusSummary([]),
                    'nodes' => [],
                ];

                continue;
            }

            $supervisor = $supervisorFactory();

            try {
                $supervisor->prepareForNodes($workerName, 'db-backed', $nodes);
                $reports[] = (new WorkerStatusReportBuilder())->workerReport(
                    workerName: $workerName,
                    assignmentMode: 'db-backed',
                    statuses: $supervisor->runtimeStatuses(),
                );
            } finally {
                $supervisor->shutdown();
            }
        }

        return $reports;
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

        $given = array_filter([$pbx, $cluster, $tag, $provider, $allActive ?: null]);

        if (count($given) !== 1) {
            return null;
        }

        if ($pbx !== null) {
            try {
                $node = $registry->findBySlug($pbx);
            } catch (PbxNotFoundException $e) {
                throw new \InvalidArgumentException($e->getMessage(), previous: $e);
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

    /**
     * @return list<string>
     */
    private function workerNames(): array
    {
        $workers = $this->option('worker');

        if (! is_array($workers)) {
            return ['esl-worker'];
        }

        $workers = array_values(array_filter(
            $workers,
            static fn (mixed $value): bool => is_string($value) && $value !== '',
        ));

        return $workers === [] ? ['esl-worker'] : $workers;
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
     * @param  array<string, mixed>  $value
     */
    private function jsonString(array $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT) ?: '{}';
    }
}
