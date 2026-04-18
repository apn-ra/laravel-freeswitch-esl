<?php

namespace ApnTalk\LaravelFreeswitchEsl\Console\Commands;

use ApnTalk\LaravelFreeswitchEsl\Console\Support\WorkerCheckpointHistoryReportBuilder;
use ApnTalk\LaravelFreeswitchEsl\Contracts\ConnectionFactoryInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\ConnectionResolverInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\MetricsRecorderInterface;
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

class FreeSwitchWorkerCheckpointStatusCommand extends Command
{
    protected $signature = 'freeswitch:worker:checkpoint-status
                            {--worker=*          : Worker name(s) used for reporting and, with --db, DB assignment lookup}
                            {--db                : Use DB-backed worker_assignments instead of CLI targeting flags}
                            {--pbx=              : [ephemeral] Target a single PBX node by slug}
                            {--cluster=          : [ephemeral] Target all nodes in a named cluster}
                            {--tag=              : [ephemeral] Target all nodes matching a tag}
                            {--provider=         : [ephemeral] Target all nodes for a provider code}
                            {--all-active        : [ephemeral] Target all currently active PBX nodes}
                            {--pbx-node=         : Filter historical scopes by PBX node slug}
                            {--connection-profile= : Filter historical scopes by connection profile name}
                            {--reason=           : Filter historical scopes by latest checkpoint reason}
                            {--limit=25          : Maximum number of worker scope results to return (clamped to 100)}
                            {--offset=0          : Offset into the filtered worker scope results}
                            {--window-hours=24   : Only count/history checkpoints saved within the last N hours}
                            {--history-limit=5   : Bounded maximum history entries per scope (clamped to 50)}
                            {--include-history   : Include bounded checkpoint history entries per scope}';

    protected $description = 'Report machine-readable historical checkpoint posture for one or more worker scopes';

    public function handle(
        PbxRegistryInterface $registry,
        WorkerAssignmentResolverInterface $assignmentResolver,
        ConnectionResolverInterface $connectionResolver,
        ConnectionFactoryInterface $connectionFactory,
        RuntimeRunnerInterface $runtimeRunner,
        MetricsRecorderInterface $metrics,
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
            metrics: $metrics,
            checkpointManager: $checkpointManager,
            drainTimeoutMilliseconds: (int) $config->get('freeswitch-esl.drain_defaults.timeout_ms', 30000),
            checkpointIntervalSeconds: (int) $config->get('freeswitch-esl.worker_defaults.checkpoint_interval_seconds', 60),
        );

        $windowHours = $this->windowHours();
        $historyLimit = $this->historyLimit();
        $limit = $this->limitValue();
        $offset = $this->offsetValue();
        $savedFrom = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify(sprintf('-%d hours', $windowHours));
        $includeHistory = $this->booleanOption('include-history');
        $filters = $this->filters();
        $retentionMetadata = $checkpointManager->historicalRetentionMetadata($windowHours);

        try {
            $reportScopes = $this->booleanOption('db')
                ? $this->dbBackedScopes($assignmentResolver, $supervisorFactory, $checkpointManager, $historyLimit, $savedFrom, $includeHistory)
                : [$this->ephemeralScope($registry, $supervisorFactory, $checkpointManager, $historyLimit, $savedFrom, $includeHistory)];

            $filteredScopes = $this->applyFilters($reportScopes, $filters);
            $paginatedScopes = array_slice($filteredScopes, $offset, $limit);
            $hasMore = count($filteredScopes) > ($offset + count($paginatedScopes));

            $this->line($this->jsonString(
                (new WorkerCheckpointHistoryReportBuilder)->report(
                    scopes: $paginatedScopes,
                    windowHours: $windowHours,
                    historyLimit: $historyLimit,
                    includeHistory: $includeHistory,
                    filters: $filters,
                    retentionMetadata: $retentionMetadata,
                    limit: $limit,
                    offset: $offset,
                    hasMore: $hasMore,
                ),
            ));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function ephemeralScope(
        PbxRegistryInterface $registry,
        \Closure $supervisorFactory,
        WorkerReplayCheckpointManager $checkpointManager,
        int $historyLimit,
        \DateTimeImmutable $savedFrom,
        bool $includeHistory,
    ): array {
        $workerNames = $this->workerNames();

        if (count($workerNames) > 1) {
            throw new \InvalidArgumentException(
                'Multiple --worker values are only supported with --db on freeswitch:worker:checkpoint-status.',
            );
        }

        $workerName = $workerNames[0] ?? 'esl-worker';
        $assignment = $this->buildEphemeralAssignment($workerName, $registry);

        if ($assignment === null) {
            throw new \InvalidArgumentException(
                'Provide exactly one targeting flag: --pbx=<slug>, --cluster=<name>, --tag=<name>, --provider=<code>, or --all-active. Use --db to load worker_assignments scopes instead.',
            );
        }

        $supervisor = $supervisorFactory();

        try {
            $supervisor->prepare($assignment);

            return $this->scopeReport(
                workerName: $workerName,
                assignmentMode: $assignment->assignmentMode,
                supervisor: $supervisor,
                checkpointManager: $checkpointManager,
                historyLimit: $historyLimit,
                savedFrom: $savedFrom,
                includeHistory: $includeHistory,
            );
        } finally {
            $supervisor->shutdown();
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function dbBackedScopes(
        WorkerAssignmentResolverInterface $assignmentResolver,
        \Closure $supervisorFactory,
        WorkerReplayCheckpointManager $checkpointManager,
        int $historyLimit,
        \DateTimeImmutable $savedFrom,
        bool $includeHistory,
    ): array {
        $reports = [];

        foreach ($this->workerNames() as $workerName) {
            $nodes = $assignmentResolver->resolveForWorkerName($workerName);

            if ($nodes === []) {
                $reports[] = [
                    'worker_name' => $workerName,
                    'assignment_mode' => 'db-backed',
                    'scope_count' => 0,
                    'scopes' => [],
                ];

                continue;
            }

            $supervisor = $supervisorFactory();

            try {
                $supervisor->prepareForNodes($workerName, 'db-backed', $nodes);
                $reports[] = $this->scopeReport(
                    workerName: $workerName,
                    assignmentMode: 'db-backed',
                    supervisor: $supervisor,
                    checkpointManager: $checkpointManager,
                    historyLimit: $historyLimit,
                    savedFrom: $savedFrom,
                    includeHistory: $includeHistory,
                );
            } finally {
                $supervisor->shutdown();
            }
        }

        return $reports;
    }

    /**
     * @param  list<array<string, mixed>>  $reports
     * @param  array<string, string|null>  $filters
     * @return list<array<string, mixed>>
     */
    private function applyFilters(array $reports, array $filters): array
    {
        $filtered = [];
        $hasActiveFilters = $this->hasActiveFilters($filters);

        foreach ($reports as $report) {
            $scopes = $report['scopes'] ?? [];

            if (! is_array($scopes)) {
                continue;
            }

            if ($scopes === [] && ! $hasActiveFilters) {
                $filtered[] = $report;

                continue;
            }

            $matchingScopes = array_values(array_filter(
                $scopes,
                fn (mixed $scope): bool => is_array($scope) && $this->scopeMatchesFilters($scope, $filters),
            ));

            if ($matchingScopes === []) {
                continue;
            }

            $report['scope_count'] = count($matchingScopes);
            $report['scopes'] = $matchingScopes;
            $filtered[] = $report;
        }

        usort($filtered, static function (array $left, array $right): int {
            $workerComparison = (string) ($left['worker_name'] ?? '') <=> (string) ($right['worker_name'] ?? '');

            if ($workerComparison !== 0) {
                return $workerComparison;
            }

            return (string) ($left['assignment_mode'] ?? '') <=> (string) ($right['assignment_mode'] ?? '');
        });

        return $filtered;
    }

    /**
     * @param  array<string, string|null>  $filters
     */
    private function hasActiveFilters(array $filters): bool
    {
        foreach ($filters as $value) {
            if ($value !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $scope
     * @param  array<string, string|null>  $filters
     */
    private function scopeMatchesFilters(array $scope, array $filters): bool
    {
        $provider = $filters['provider'] ?? null;
        $pbxNode = $filters['pbx_node'] ?? null;
        $connectionProfile = $filters['connection_profile'] ?? null;
        $reason = $filters['reason'] ?? null;

        if ($provider !== null && ($scope['provider_code'] ?? null) !== $provider) {
            return false;
        }

        if ($pbxNode !== null && ($scope['pbx_node_slug'] ?? null) !== $pbxNode) {
            return false;
        }

        if ($connectionProfile !== null && ($scope['connection_profile_name'] ?? null) !== $connectionProfile) {
            return false;
        }

        if ($reason !== null && ($scope['latest_checkpoint_reason'] ?? null) !== $reason) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function scopeReport(
        string $workerName,
        string $assignmentMode,
        WorkerSupervisor $supervisor,
        WorkerReplayCheckpointManager $checkpointManager,
        int $historyLimit,
        \DateTimeImmutable $savedFrom,
        bool $includeHistory,
    ): array {
        $scopes = [];

        foreach ($supervisor->runtimes() as $slug => $runtime) {
            $context = $runtime->resolvedContext();

            if ($context === null) {
                continue;
            }

            $scopes[] = $checkpointManager->historicalSummary(
                workerName: $workerName,
                context: $context,
                historyLimit: $historyLimit,
                savedFrom: $savedFrom,
                includeHistory: $includeHistory,
                windowHours: $this->windowHours(),
            );
        }

        return [
            'worker_name' => $workerName,
            'assignment_mode' => $assignmentMode,
            'scope_count' => count($scopes),
            'scopes' => $scopes,
        ];
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

    private function windowHours(): int
    {
        $value = $this->option('window-hours');

        return max(1, min((int) $value, 24 * 30));
    }

    private function historyLimit(): int
    {
        $value = $this->option('history-limit');

        return max(1, min((int) $value, 50));
    }

    private function limitValue(): int
    {
        $value = $this->option('limit');

        return max(1, min((int) $value, 100));
    }

    private function offsetValue(): int
    {
        $value = $this->option('offset');

        return max(0, (int) $value);
    }

    /**
     * @return array<string, string|null>
     */
    private function filters(): array
    {
        return [
            'provider' => $this->stringOption('provider'),
            'pbx_node' => $this->stringOption('pbx-node'),
            'connection_profile' => $this->stringOption('connection-profile'),
            'reason' => $this->stringOption('reason'),
        ];
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
