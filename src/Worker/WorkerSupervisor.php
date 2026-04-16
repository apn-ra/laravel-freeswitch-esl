<?php

namespace ApnTalk\LaravelFreeswitchEsl\Worker;

use ApnTalk\LaravelFreeswitchEsl\Contracts\ConnectionFactoryInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\ConnectionResolverInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\WorkerAssignmentResolverInterface;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\PbxNode;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\WorkerAssignment;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\WorkerStatus;
use ApnTalk\LaravelFreeswitchEsl\Contracts\RuntimeHandoffInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\RuntimeRunnerInterface;
use ApnTalk\LaravelFreeswitchEsl\Exceptions\WorkerException;
use Psr\Log\LoggerInterface;

/**
 * Supervises one or more WorkerRuntime instances for an assignment scope.
 *
 * WorkerSupervisor is responsible for:
 *   - resolving the target PBX node set from a WorkerAssignment (ephemeral path)
 *   - or accepting a pre-resolved PbxNode set (DB-backed path)
 *   - bootstrapping one WorkerRuntime per node
 *   - coordinating shutdown and drain across all node runtimes
 *   - isolating failures in one node scope from others
 *
 * Two entry points exist to serve the two worker assignment paths:
 *
 *   run(WorkerAssignment)
 *     Ephemeral path. The supervisor resolves nodes from the assignment scope
 *     at startup. Used when targeting flags (--pbx, --cluster, etc.) are
 *     passed directly on the CLI. The resulting assignment is NOT persisted
 *     to the worker_assignments table.
 *
 *   runForNodes(string $workerName, string $assignmentScope, PbxNode[] $nodes)
 *     DB-backed path. Nodes are resolved externally (e.g. from WorkerAssignmentResolver
 *     looking up the worker_assignments table) and passed in directly. The supervisor
 *     does not perform additional resolution. Used when --db is passed on the CLI.
 *
 * This is the package-level orchestration layer. The underlying runtime
 * loop per node is delegated to WorkerRuntime (and eventually apntalk/esl-react).
 */
class WorkerSupervisor
{
    /** @var WorkerRuntime[] keyed by pbx_node_slug */
    private array $runtimes = [];

    public function __construct(
        private readonly WorkerAssignmentResolverInterface $assignmentResolver,
        private readonly ConnectionResolverInterface $connectionResolver,
        private readonly ConnectionFactoryInterface $connectionFactory,
        private readonly RuntimeRunnerInterface $runtimeRunner,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Ephemeral path: resolve nodes from the assignment scope and boot runtimes.
     *
     * The assignment is NOT persisted to the worker_assignments table.
     *
     * @throws WorkerException if no nodes resolve from the assignment
     */
    public function run(WorkerAssignment $assignment): void
    {
        $nodes = $this->assignmentResolver->resolveNodes($assignment);

        if (empty($nodes)) {
            throw WorkerException::noNodesResolved($assignment->workerName);
        }

        $this->bootRuntimes(
            workerName: $assignment->workerName,
            assignmentScope: $assignment->assignmentMode,
            nodes: $nodes,
        );
    }

    /**
     * DB-backed path: accept pre-resolved nodes and boot runtimes directly.
     *
     * Nodes are provided by the caller (typically resolved from the
     * worker_assignments table via WorkerAssignmentResolver::resolveForWorkerName).
     * The supervisor performs no additional assignment resolution.
     *
     * @param  PbxNode[]  $nodes
     * @throws WorkerException if $nodes is empty
     */
    public function runForNodes(string $workerName, string $assignmentScope, array $nodes): void
    {
        if (empty($nodes)) {
            throw WorkerException::noNodesResolved($workerName);
        }

        $this->bootRuntimes(
            workerName: $workerName,
            assignmentScope: $assignmentScope,
            nodes: $nodes,
        );
    }

    public function shutdown(): void
    {
        foreach ($this->runtimes as $runtime) {
            try {
                $runtime->shutdown();
            } catch (\Throwable $e) {
                $this->logger->warning('Error during worker shutdown', [
                    'session_id' => $runtime->sessionId(),
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        $this->runtimes = [];
    }

    public function drain(): void
    {
        foreach ($this->runtimes as $runtime) {
            $runtime->drain();
        }
    }

    /**
     * @return WorkerRuntime[]
     */
    public function runtimes(): array
    {
        return $this->runtimes;
    }

    /**
     * Return per-node runtime status snapshots keyed by PBX node slug.
     *
     * This is a Laravel-scaffolding inspection surface only. It reports
     * retained handoff-prepared state; it does not imply a live async runtime.
     *
     * @return array<string, WorkerStatus>
     */
    public function runtimeStatuses(): array
    {
        $statuses = [];

        foreach ($this->runtimes as $slug => $runtime) {
            $statuses[$slug] = $runtime->status();
        }

        return $statuses;
    }

    /**
     * Return prepared adapter-facing runtime handoffs keyed by PBX node slug.
     *
     * Only runtimes that have completed boot() contribute a handoff here. The
     * returned bundles are prepared for future adapter consumption, not live sessions.
     *
     * @return array<string, RuntimeHandoffInterface>
     */
    public function runtimeHandoffs(): array
    {
        $handoffs = [];

        foreach ($this->runtimes as $slug => $runtime) {
            $handoff = $runtime->runtimeHandoff();

            if ($handoff !== null) {
                $handoffs[$slug] = $handoff;
            }
        }

        return $handoffs;
    }

    /**
     * @param  PbxNode[]  $nodes
     */
    private function bootRuntimes(string $workerName, string $assignmentScope, array $nodes): void
    {
        $this->logger->info('WorkerSupervisor starting', [
            'worker_name'      => $workerName,
            'assignment_scope' => $assignmentScope,
            'node_count'       => count($nodes),
            'node_slugs'       => array_map(fn (PbxNode $n) => $n->slug, $nodes),
        ]);

        foreach ($nodes as $node) {
            $runtime = new WorkerRuntime(
                workerName: $workerName,
                node: $node,
                connectionResolver: $this->connectionResolver,
                connectionFactory: $this->connectionFactory,
                runtimeRunner: $this->runtimeRunner,
                logger: $this->logger,
            );

            $this->runtimes[$node->slug] = $runtime;

            try {
                $runtime->boot();
                $runtime->run();
            } catch (\Throwable $e) {
                $this->logger->error('WorkerRuntime failed for node', [
                    'worker_name'   => $workerName,
                    'pbx_node_slug' => $node->slug,
                    'error'         => $e->getMessage(),
                ]);
                // Isolate: continue to next node rather than aborting the whole supervisor
            }
        }
    }
}
