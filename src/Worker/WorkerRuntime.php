<?php

namespace ApnTalk\LaravelFreeswitchEsl\Worker;

use ApnTalk\LaravelFreeswitchEsl\Contracts\ConnectionFactoryInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\ConnectionResolverInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\WorkerInterface;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\ConnectionContext;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\PbxNode;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\WorkerStatus;
use ApnTalk\LaravelFreeswitchEsl\Exceptions\WorkerException;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslCoreConnectionHandle;
use Psr\Log\LoggerInterface;

/**
 * Single-node worker runtime managed by this Laravel package.
 *
 * Responsibilities owned here:
 *   - worker session identity
 *   - connection-context resolution per node
 *   - connection-handoff creation and persistence for downstream runtime consumers
 *   - graceful drain and shutdown coordination
 *   - status reporting
 *
 * Responsibilities delegated to apntalk/esl-react (not yet available):
 *   - actual TCP/TLS connection lifecycle
 *   - reconnect/backoff loop
 *   - subscription management
 *   - heartbeat monitoring
 *
 * In the current package posture, `run()` is a non-live stub that verifies
 * the retained handoff state and returns. When apntalk/esl-react is available,
 * it should delegate to the ReactPHP runtime loop using the already-prepared
 * connection handle. Re-resolution is not required.
 *
 * Boundary: do NOT add ESL frame parsing or subscription primitives here.
 */
class WorkerRuntime implements WorkerInterface
{
    private string $state = WorkerStatus::STATE_BOOTING;

    private bool $draining = false;

    private int $inflightCount = 0;

    private ?\DateTimeImmutable $bootedAt = null;

    private ?\DateTimeImmutable $lastHeartbeatAt = null;

    private readonly string $sessionId;

    /**
     * Resolved and session-tagged connection context, set during boot().
     * Null before boot() is called. Consumed by run() once esl-react is wired.
     */
    private ?ConnectionContext $resolvedContext = null;

    /**
     * Package-owned runtime handoff handle created during boot().
     * Null before boot() is called. Future esl-react integration consumes this.
     */
    private ?EslCoreConnectionHandle $connectionHandle = null;

    public function __construct(
        private readonly string $workerName,
        private readonly PbxNode $node,
        private readonly ConnectionResolverInterface $connectionResolver,
        private readonly ConnectionFactoryInterface $connectionFactory,
        private readonly LoggerInterface $logger,
    ) {
        $this->sessionId = sprintf(
            '%s-%s-%s',
            $workerName,
            $node->slug,
            bin2hex(random_bytes(8))
        );
    }

    public function boot(): void
    {
        $this->logger->info('Worker booting', [
            'worker_name'   => $this->workerName,
            'session_id'    => $this->sessionId,
            'pbx_node_id'   => $this->node->id,
            'pbx_node_slug' => $this->node->slug,
            'provider_code' => $this->node->providerCode,
        ]);

        $context = $this->connectionResolver->resolveForPbxNode($this->node);

        // Attach worker session identity and persist — downstream runtime consumers
        // must use this resolved context directly without re-resolving credentials.
        $this->resolvedContext = $context->withWorkerSession($this->sessionId);
        $this->connectionHandle = $this->connectionFactory->create($this->resolvedContext);

        $this->logger->info('Connection context resolved', $this->resolvedContext->toLogContext());
        $this->logger->info('Connection handoff prepared', [
            'session_id'    => $this->sessionId,
            'pbx_node_slug' => $this->node->slug,
            'endpoint'      => $this->connectionHandle->endpoint(),
        ]);

        // In current scaffolding, "running" means boot completed and the
        // runtime handoff seam is prepared. It does not imply a live async loop.
        $this->bootedAt = new \DateTimeImmutable();
        $this->state = WorkerStatus::STATE_RUNNING;
    }

    public function run(): void
    {
        if ($this->resolvedContext === null || $this->connectionHandle === null) {
            throw WorkerException::bootFailed(
                $this->workerName,
                'run() called before boot() — runtime handoff state is incomplete'
            );
        }

        // Placeholder: real implementation delegates to apntalk/esl-react runtime loop.
        // When apntalk/esl-react is available, replace this body with:
        //
        //   $this->reactRuntime->run($this->connectionHandle);
        //
        // The handle already carries the resolved context, command boot sequence,
        // and inbound pipeline. Do not re-resolve via connectionResolver here.
        $this->logger->info('Worker running (stub — runtime loop not yet wired to apntalk/esl-react)', [
            'session_id'    => $this->sessionId,
            'pbx_node_slug' => $this->node->slug,
            'endpoint'      => $this->connectionHandle->endpoint(),
        ]);
    }

    public function drain(): void
    {
        $this->logger->info('Worker draining', [
            'session_id'    => $this->sessionId,
            'pbx_node_slug' => $this->node->slug,
            'inflight'      => $this->inflightCount,
        ]);

        $this->draining = true;
        $this->state = WorkerStatus::STATE_DRAINING;
    }

    public function shutdown(): void
    {
        $this->logger->info('Worker shutting down', [
            'session_id'    => $this->sessionId,
            'pbx_node_slug' => $this->node->slug,
        ]);

        $this->state = WorkerStatus::STATE_SHUTDOWN;
    }

    public function status(): WorkerStatus
    {
        return new WorkerStatus(
            sessionId: $this->sessionId,
            workerName: $this->workerName,
            state: $this->state,
            assignedNodeSlugs: [$this->node->slug],
            inflightCount: $this->inflightCount,
            retryAttempt: 0,
            isDraining: $this->draining,
            lastHeartbeatAt: $this->lastHeartbeatAt,
            bootedAt: $this->bootedAt,
            meta: [
                'context_resolved' => $this->resolvedContext !== null,
                'connection_handoff_prepared' => $this->connectionHandle !== null,
                'handoff_endpoint' => $this->connectionHandle?->endpoint(),
                'runtime_loop_active' => false,
            ],
        );
    }

    public function sessionId(): string
    {
        return $this->sessionId;
    }

    /**
     * Return the resolved, session-tagged connection context for this worker.
     *
     * Available after boot() completes. Returns null if boot() has not been called.
     * The esl-react runtime integration should consume this directly in run()
     * rather than re-resolving credentials from the control plane.
     */
    public function resolvedContext(): ?ConnectionContext
    {
        return $this->resolvedContext;
    }

    /**
     * Return the package-owned connection/runtime handoff handle for this worker.
     *
     * Available after boot() completes. Returns null if boot() has not been called.
     * Future runtime integrations should consume this handle rather than rebuilding
     * esl-core boot primitives from the control plane.
     */
    public function connectionHandle(): ?EslCoreConnectionHandle
    {
        return $this->connectionHandle;
    }
}
