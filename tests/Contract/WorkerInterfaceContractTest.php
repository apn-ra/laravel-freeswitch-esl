<?php

namespace ApnTalk\LaravelFreeswitchEsl\Tests\Contract;

use ApnTalk\LaravelFreeswitchEsl\Contracts\ConnectionFactoryInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\ConnectionResolverInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\WorkerInterface;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\ConnectionContext;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\PbxNode;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\WorkerStatus;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslCoreConnectionHandle;
use ApnTalk\LaravelFreeswitchEsl\Worker\WorkerRuntime;
use PHPUnit\Framework\TestCase;
use Apntalk\EslCore\Transport\InMemoryTransport;
use Psr\Log\NullLogger;

/**
 * Contract tests for WorkerInterface.
 *
 * Verifies the PUBLIC CONTRACT of the worker lifecycle. When additional
 * WorkerInterface implementations are added (e.g. for esl-react integration),
 * cover them here with the same invariants.
 */
class WorkerInterfaceContractTest extends TestCase
{
    /**
     * @return array<string, array{WorkerInterface}>
     */
    public static function workerProvider(): array
    {
        $node = new PbxNode(
            id: 1,
            providerId: 1,
            providerCode: 'freeswitch',
            name: 'Contract Test Node',
            slug: 'contract-test',
            host: '127.0.0.1',
            port: 8021,
            username: '',
            passwordSecretRef: 'secret',
            transport: 'tcp',
            isActive: true,
        );

        $context = new ConnectionContext(
            pbxNodeId: 1,
            pbxNodeSlug: 'contract-test',
            providerCode: 'freeswitch',
            host: '127.0.0.1',
            port: 8021,
            username: '',
            resolvedPassword: 'ClueCon',
            transport: 'tcp',
            connectionProfileId: null,
            connectionProfileName: 'default',
        );

        $resolver = new class ($context) implements ConnectionResolverInterface {
            public function __construct(private readonly ConnectionContext $ctx) {}

            public function resolveForNode(int $pbxNodeId): ConnectionContext
            {
                return $this->ctx;
            }

            public function resolveForSlug(string $slug): ConnectionContext
            {
                return $this->ctx;
            }

            public function resolveForPbxNode(PbxNode $node): ConnectionContext
            {
                return $this->ctx;
            }
        };

        $connectionFactory = new class ($context) implements ConnectionFactoryInterface {
            public function __construct(private readonly ConnectionContext $ctx) {}

            public function create(ConnectionContext $context): mixed
            {
                return new EslCoreConnectionHandle(
                    context: $context,
                    pipeline: new \ApnTalk\LaravelFreeswitchEsl\Integration\EslCorePipelineFactory()->createPipeline(),
                    openingSequence: new \ApnTalk\LaravelFreeswitchEsl\Integration\EslCoreCommandFactory()->buildOpeningSequence($context),
                    closingSequence: new \ApnTalk\LaravelFreeswitchEsl\Integration\EslCoreCommandFactory()->buildClosingSequence(),
                    transportOpener: fn () => new InMemoryTransport(),
                );
            }
        };

        return [
            'WorkerRuntime' => [new WorkerRuntime('contract-worker', $node, $resolver, $connectionFactory, new NullLogger())],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('workerProvider')]
    public function test_session_id_is_non_empty_string(WorkerInterface $worker): void
    {
        $this->assertNotEmpty($worker->sessionId());
        $this->assertIsString($worker->sessionId());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('workerProvider')]
    public function test_session_id_is_stable(WorkerInterface $worker): void
    {
        $this->assertSame($worker->sessionId(), $worker->sessionId());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('workerProvider')]
    public function test_status_before_boot_returns_booting_state(WorkerInterface $worker): void
    {
        $this->assertSame(WorkerStatus::STATE_BOOTING, $worker->status()->state);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('workerProvider')]
    public function test_status_before_boot_reports_handoff_as_unprepared(WorkerInterface $worker): void
    {
        $status = $worker->status();

        $this->assertFalse($status->meta['context_resolved']);
        $this->assertFalse($status->meta['connection_handoff_prepared']);
        $this->assertNull($status->meta['handoff_endpoint']);
        $this->assertFalse($status->meta['runtime_loop_active']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('workerProvider')]
    public function test_status_returns_worker_status_instance(WorkerInterface $worker): void
    {
        $this->assertInstanceOf(WorkerStatus::class, $worker->status());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('workerProvider')]
    public function test_boot_transitions_state_to_running(WorkerInterface $worker): void
    {
        $worker->boot();

        $this->assertSame(WorkerStatus::STATE_RUNNING, $worker->status()->state);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('workerProvider')]
    public function test_boot_reports_handoff_as_prepared(WorkerInterface $worker): void
    {
        $worker->boot();
        $status = $worker->status();

        $this->assertTrue($status->meta['context_resolved']);
        $this->assertTrue($status->meta['connection_handoff_prepared']);
        $this->assertNotNull($status->meta['handoff_endpoint']);
        $this->assertFalse($status->meta['runtime_loop_active']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('workerProvider')]
    public function test_run_after_boot_does_not_throw(WorkerInterface $worker): void
    {
        $worker->boot();

        // Must not throw in the stub implementation
        $this->expectNotToPerformAssertions();
        $worker->run();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('workerProvider')]
    public function test_drain_transitions_to_draining_state(WorkerInterface $worker): void
    {
        $worker->boot();
        $worker->drain();

        $this->assertTrue($worker->status()->isDraining());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('workerProvider')]
    public function test_shutdown_transitions_to_shutdown_state(WorkerInterface $worker): void
    {
        $worker->boot();
        $worker->shutdown();

        $this->assertTrue($worker->status()->isShutdown());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('workerProvider')]
    public function test_session_id_present_in_status(WorkerInterface $worker): void
    {
        $this->assertSame($worker->sessionId(), $worker->status()->sessionId);
    }
}
