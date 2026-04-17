<?php

namespace ApnTalk\LaravelFreeswitchEsl\Worker;

use ApnTalk\LaravelFreeswitchEsl\Contracts\ConnectionFactoryInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\ConnectionResolverInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\WorkerInterface;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\ConnectionContext;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\PbxNode;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\WorkerStatus;
use ApnTalk\LaravelFreeswitchEsl\Contracts\RuntimeHandoffInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\RuntimeRunnerFeedbackProviderInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\RuntimeRunnerInterface;
use ApnTalk\LaravelFreeswitchEsl\Exceptions\WorkerException;
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
 * Responsibilities delegated to apntalk/esl-react:
 *   - actual TCP/TLS connection lifecycle
 *   - reconnect/backoff loop
 *   - subscription management
 *   - heartbeat monitoring
 *
 * In the current package posture, `run()` verifies the retained handoff state
 * and invokes the Laravel-owned RuntimeRunnerInterface seam. The default
 * binding adapts the prepared handoff into apntalk/esl-react's runner input.
 * Laravel still does not own the live async loop or runtime session health.
 *
 * Boundary: do NOT add ESL frame parsing or subscription primitives here.
 */
class WorkerRuntime implements WorkerInterface
{
    private string $state = WorkerStatus::STATE_BOOTING;

    private bool $draining = false;

    private int $inflightCount = 0;

    private bool $runtimeRunnerInvoked = false;

    private ?\DateTimeImmutable $bootedAt = null;

    private ?\DateTimeImmutable $lastHeartbeatAt = null;

    private readonly string $sessionId;

    /**
     * Resolved and session-tagged connection context, set during boot().
     * Null before boot() is called. Consumed by run() through the runner seam.
     */
    private ?ConnectionContext $resolvedContext = null;

    /**
     * Package-owned runtime handoff handle created during boot().
     * Null before boot() is called. Runtime adapters consume this.
     */
    private ?RuntimeHandoffInterface $runtimeHandoff = null;

    public function __construct(
        private readonly string $workerName,
        private readonly PbxNode $node,
        private readonly ConnectionResolverInterface $connectionResolver,
        private readonly ConnectionFactoryInterface $connectionFactory,
        private readonly RuntimeRunnerInterface $runtimeRunner,
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
        $this->runtimeHandoff = $this->connectionFactory->create($this->resolvedContext);

        $this->logger->info('Connection context resolved', $this->resolvedContext->toLogContext());
        $this->logger->info('Connection handoff prepared', [
            'session_id'    => $this->sessionId,
            'pbx_node_slug' => $this->node->slug,
            'endpoint'      => $this->runtimeHandoff->endpoint(),
        ]);

        // In current scaffolding, "running" means boot completed and the
        // runtime handoff seam is prepared. It does not imply a live async loop.
        $this->bootedAt = new \DateTimeImmutable();
        $this->state = WorkerStatus::STATE_RUNNING;
    }

    public function run(): void
    {
        if ($this->resolvedContext === null || $this->runtimeHandoff === null) {
            throw WorkerException::bootFailed(
                $this->workerName,
                'run() called before boot() — runtime handoff state is incomplete'
            );
        }

        // Current seam: invoke the Laravel-owned runtime runner contract. The
        // bound implementation owns adapter invocation; live async runtime state
        // remains owned by apntalk/esl-react, not this Laravel worker scaffold.
        $this->logger->info('Invoking runtime runner', [
            'session_id'    => $this->sessionId,
            'pbx_node_slug' => $this->node->slug,
            'endpoint'      => $this->runtimeHandoff->endpoint(),
            'runtime_runner'=> $this->runtimeRunner::class,
        ]);

        $this->runtimeRunner->run($this->runtimeHandoff);
        $this->runtimeRunnerInvoked = true;

        $this->logger->info('Worker run completed after runtime runner invocation', [
            'session_id'    => $this->sessionId,
            'pbx_node_slug' => $this->node->slug,
            'endpoint'      => $this->runtimeHandoff->endpoint(),
            'runtime_runner'=> $this->runtimeRunner::class,
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
            meta: array_merge([
                'context_resolved' => $this->resolvedContext !== null,
                'connection_handoff_prepared' => $this->runtimeHandoff !== null,
                'runtime_adapter_ready' => $this->runtimeHandoff !== null,
                'handoff_endpoint' => $this->runtimeHandoff?->endpoint(),
                'runtime_handoff_contract' => $this->runtimeHandoff !== null ? RuntimeHandoffInterface::class : null,
                'runtime_handoff_class' => $this->runtimeHandoff !== null ? $this->runtimeHandoff::class : null,
                'runtime_runner_invoked' => $this->runtimeRunnerInvoked,
                'runtime_runner_contract' => RuntimeRunnerInterface::class,
                'runtime_runner_class' => $this->runtimeRunner::class,
                'runtime_loop_active' => false,
                'runtime_loop_active_source' => 'not-observed-by-laravel',
                'runtime_feedback_observed' => false,
                'runtime_feedback_source' => null,
                'runtime_feedback_delivery' => null,
                'runtime_push_lifecycle_observed' => false,
                'runtime_runner_state' => null,
                'runtime_runner_endpoint' => null,
                'runtime_runner_session_id' => null,
                'runtime_startup_error_class' => null,
                'runtime_startup_error' => null,
                'runtime_connection_state' => null,
                'runtime_session_state' => null,
                'runtime_connected' => null,
                'runtime_authenticated' => null,
                'runtime_live' => null,
                'runtime_reconnecting' => null,
                'runtime_draining' => null,
                'runtime_stopped' => null,
                'runtime_reconnect_attempts' => null,
                'runtime_last_heartbeat_at_micros' => null,
                'runtime_last_error_class' => null,
                'runtime_last_error_message' => null,
            ], $this->runtimeFeedbackMeta()),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function runtimeFeedbackMeta(): array
    {
        if (! $this->runtimeRunner instanceof RuntimeRunnerFeedbackProviderInterface) {
            return [];
        }

        $feedback = $this->runtimeRunner->runtimeFeedback();

        if ($feedback === null) {
            return [];
        }

        return $feedback->toMeta();
    }

    public function sessionId(): string
    {
        return $this->sessionId;
    }

    /**
     * Return the resolved, session-tagged connection context for this worker.
     *
     * Available after boot() completes. Returns null if boot() has not been called.
     * Runtime runner adapters should consume this directly in run()
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
     * Runtime integrations should consume this handle rather than rebuilding
     * esl-core boot primitives from the control plane.
     */
    public function connectionHandle(): ?RuntimeHandoffInterface
    {
        return $this->runtimeHandoff;
    }

    /**
     * Return the adapter-facing prepared runtime handoff bundle for this worker.
     *
     * This is the preferred seam for runtime adapters. It is available after
     * boot(); live session ownership remains outside this Laravel worker.
     */
    public function runtimeHandoff(): ?RuntimeHandoffInterface
    {
        return $this->runtimeHandoff;
    }
}
