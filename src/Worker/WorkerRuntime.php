<?php

namespace ApnTalk\LaravelFreeswitchEsl\Worker;

use ApnTalk\LaravelFreeswitchEsl\Contracts\ConnectionResolverInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\WorkerInterface;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\PbxNode;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\WorkerStatus;
use Psr\Log\LoggerInterface;

/**
 * Single-node worker runtime managed by this Laravel package.
 *
 * Responsibilities owned here:
 *   - worker session identity
 *   - connection-context resolution per node
 *   - graceful drain and shutdown coordination
 *   - status reporting
 *
 * Responsibilities delegated to apntalk/esl-react (not yet available):
 *   - actual TCP/TLS connection lifecycle
 *   - reconnect/backoff loop
 *   - subscription management
 *   - heartbeat monitoring
 *
 * When apntalk/esl-react is available, the `run()` method should delegate
 * to the ReactPHP runtime loop. For now it holds a placeholder that logs the
 * context and returns, allowing the control-plane layer to be tested in
 * isolation.
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

    public function __construct(
        private readonly string $workerName,
        private readonly PbxNode $node,
        private readonly ConnectionResolverInterface $connectionResolver,
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
            'worker_name'  => $this->workerName,
            'session_id'   => $this->sessionId,
            'pbx_node_id'  => $this->node->id,
            'pbx_node_slug' => $this->node->slug,
            'provider_code' => $this->node->providerCode,
        ]);

        $context = $this->connectionResolver->resolveForPbxNode($this->node);

        $this->logger->info('Connection context resolved', $context->toLogContext());

        $this->bootedAt = new \DateTimeImmutable();
        $this->state = WorkerStatus::STATE_RUNNING;
    }

    public function run(): void
    {
        // Placeholder: real implementation delegates to apntalk/esl-react runtime loop.
        // When apntalk/esl-react is available, replace this body with:
        //   $this->reactRuntime->run($context, $this->sessionId);
        $this->logger->info('Worker running (stub — runtime loop not yet wired to apntalk/esl-react)', [
            'session_id'    => $this->sessionId,
            'pbx_node_slug' => $this->node->slug,
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
        );
    }

    public function sessionId(): string
    {
        return $this->sessionId;
    }
}
