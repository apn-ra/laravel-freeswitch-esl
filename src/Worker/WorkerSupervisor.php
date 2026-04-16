<?php

namespace ApnTalk\LaravelFreeswitchEsl\Worker;

use ApnTalk\LaravelFreeswitchEsl\Contracts\ConnectionResolverInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\WorkerAssignmentResolverInterface;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\PbxNode;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\WorkerAssignment;
use ApnTalk\LaravelFreeswitchEsl\Exceptions\WorkerException;
use Psr\Log\LoggerInterface;

/**
 * Supervises one or more WorkerRuntime instances for an assignment scope.
 *
 * WorkerSupervisor is responsible for:
 *   - resolving the target PBX node set from the assignment
 *   - bootstrapping one WorkerRuntime per node
 *   - coordinating shutdown and drain across all node runtimes
 *   - isolating failures in one node scope from others
 *
 * This is the package-level orchestration layer. The underlying runtime
 * loop per node is delegated to WorkerRuntime (and eventually apntalk/esl-react).
 */
class WorkerSupervisor
{
    /** @var WorkerRuntime[] */
    private array $runtimes = [];

    public function __construct(
        private readonly WorkerAssignmentResolverInterface $assignmentResolver,
        private readonly ConnectionResolverInterface $connectionResolver,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Boot and run workers for the given assignment scope.
     *
     * @throws WorkerException if no nodes resolve from the assignment
     */
    public function run(WorkerAssignment $assignment): void
    {
        $nodes = $this->assignmentResolver->resolveNodes($assignment);

        if (empty($nodes)) {
            throw WorkerException::noNodesResolved($assignment->workerName);
        }

        $this->logger->info('WorkerSupervisor starting', [
            'worker_name'   => $assignment->workerName,
            'mode'          => $assignment->assignmentMode,
            'node_count'    => count($nodes),
            'node_slugs'    => array_map(fn (PbxNode $n) => $n->slug, $nodes),
        ]);

        foreach ($nodes as $node) {
            $runtime = new WorkerRuntime(
                workerName: $assignment->workerName,
                node: $node,
                connectionResolver: $this->connectionResolver,
                logger: $this->logger,
            );

            $this->runtimes[$node->slug] = $runtime;

            try {
                $runtime->boot();
                $runtime->run();
            } catch (\Throwable $e) {
                $this->logger->error('WorkerRuntime failed for node', [
                    'worker_name'   => $assignment->workerName,
                    'pbx_node_slug' => $node->slug,
                    'error'         => $e->getMessage(),
                ]);
                // Isolate: continue to next node rather than aborting the whole supervisor
            }
        }
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
}
