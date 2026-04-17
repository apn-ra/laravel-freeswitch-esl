<?php

namespace ApnTalk\LaravelFreeswitchEsl\Tests\Integration\Console;

use ApnTalk\LaravelFreeswitchEsl\Contracts\ConnectionFactoryInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\ConnectionResolverInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\PbxRegistryInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\RuntimeHandoffInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\RuntimeRunnerInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\WorkerAssignmentResolverInterface;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\ConnectionContext;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\PbxNode;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\WorkerAssignment;
use ApnTalk\LaravelFreeswitchEsl\Exceptions\PbxNotFoundException;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslCoreCommandFactory;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslCoreConnectionHandle;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslCorePipelineFactory;
use ApnTalk\LaravelFreeswitchEsl\Tests\TestCase;
use Apntalk\EslCore\Transport\InMemoryTransport;
use Illuminate\Contracts\Console\Kernel;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class FreeSwitchWorkerCommandTest extends TestCase
{
    public function test_worker_command_is_registered_with_the_console_kernel(): void
    {
        /** @var Kernel $kernel */
        $kernel = $this->app->make(Kernel::class);

        $this->assertArrayHasKey('freeswitch:worker', $kernel->all());
    }

    public function test_worker_command_ephemeral_pbx_path_prepares_runtime_handoff(): void
    {
        $node = $this->makeNode(1, 'primary-fs');
        $registry = new class ($node) implements PbxRegistryInterface {
            public int $findBySlugCalls = 0;

            public function __construct(private readonly PbxNode $node) {}

            public function findById(int $id): PbxNode
            {
                return $this->node;
            }

            public function findBySlug(string $slug): PbxNode
            {
                $this->findBySlugCalls++;

                if ($slug !== $this->node->slug) {
                    throw new PbxNotFoundException($slug);
                }

                return $this->node;
            }

            public function allActive(): array
            {
                return [$this->node];
            }

            public function allByCluster(string $cluster): array
            {
                return [$this->node];
            }

            public function allByTags(array $tags): array
            {
                return [$this->node];
            }

            public function allByProvider(string $providerCode): array
            {
                return [$this->node];
            }
        };

        $assignmentResolver = new class ($node) implements WorkerAssignmentResolverInterface {
            public int $resolveNodesCalls = 0;
            public int $resolveForWorkerNameCalls = 0;
            public ?WorkerAssignment $lastAssignment = null;

            public function __construct(private readonly PbxNode $node) {}

            public function resolveNodes(WorkerAssignment $assignment): array
            {
                $this->resolveNodesCalls++;
                $this->lastAssignment = $assignment;

                return [$this->node];
            }

            public function resolveForWorkerName(string $workerName): array
            {
                $this->resolveForWorkerNameCalls++;

                return [];
            }
        };

        $connectionResolver = new class implements ConnectionResolverInterface {
            /** @var list<string> */
            public array $resolvedNodeSlugs = [];

            public function resolveForNode(int $pbxNodeId): ConnectionContext
            {
                return $this->makeContext('primary-fs');
            }

            public function resolveForSlug(string $slug): ConnectionContext
            {
                return $this->makeContext($slug);
            }

            public function resolveForPbxNode(PbxNode $node): ConnectionContext
            {
                $this->resolvedNodeSlugs[] = $node->slug;

                return $this->makeContext($node->slug);
            }

            private function makeContext(string $slug): ConnectionContext
            {
                return new ConnectionContext(
                    pbxNodeId: 1,
                    pbxNodeSlug: $slug,
                    providerCode: 'freeswitch',
                    host: '127.0.0.1',
                    port: 8021,
                    username: '',
                    resolvedPassword: 'ClueCon',
                    transport: 'tcp',
                    connectionProfileId: null,
                    connectionProfileName: 'default',
                );
            }
        };

        $connectionFactory = new class implements ConnectionFactoryInterface {
            public int $createCalls = 0;

            /** @var list<ConnectionContext> */
            public array $contexts = [];

            public function create(ConnectionContext $context): EslCoreConnectionHandle
            {
                $this->createCalls++;
                $this->contexts[] = $context;

                $commandFactory = new EslCoreCommandFactory();

                return new EslCoreConnectionHandle(
                    context: $context,
                    pipeline: (new EslCorePipelineFactory())->createPipeline(),
                    openingSequence: $commandFactory->buildOpeningSequence($context),
                    closingSequence: $commandFactory->buildClosingSequence(),
                    transportOpener: fn () => new InMemoryTransport(),
                );
            }
        };

        $runtimeRunner = new class implements RuntimeRunnerInterface {
            public int $runCalls = 0;

            public function run(RuntimeHandoffInterface $handoff): void
            {
                $this->runCalls++;
            }
        };

        $this->app->instance(PbxRegistryInterface::class, $registry);
        $this->app->instance(WorkerAssignmentResolverInterface::class, $assignmentResolver);
        $this->app->instance(ConnectionResolverInterface::class, $connectionResolver);
        $this->app->instance(ConnectionFactoryInterface::class, $connectionFactory);
        $this->app->instance(RuntimeRunnerInterface::class, $runtimeRunner);
        $this->app->instance(LoggerInterface::class, new NullLogger());

        $this->artisan('freeswitch:worker', [
            '--worker' => 'ingest-worker',
            '--pbx' => 'primary-fs',
        ])
            ->expectsOutputToContain('Starting worker [ingest-worker] in [node] mode')
            ->expectsOutputToContain('Prepared runtime handoff for 1/1 node(s); runtime runner invoked for 1/1 node(s); push lifecycle observed for 0/1 node(s); live runtime observed for 0/1 node(s).')
            ->assertExitCode(0);

        $this->assertSame(1, $registry->findBySlugCalls);
        $this->assertSame(1, $assignmentResolver->resolveNodesCalls);
        $this->assertSame(0, $assignmentResolver->resolveForWorkerNameCalls);
        $this->assertNotNull($assignmentResolver->lastAssignment);
        $this->assertSame('node', $assignmentResolver->lastAssignment->assignmentMode);
        $this->assertSame(1, $connectionFactory->createCalls);
        $this->assertCount(1, $connectionFactory->contexts);
        $this->assertSame('primary-fs', $connectionFactory->contexts[0]->pbxNodeSlug);
        $this->assertNotNull($connectionFactory->contexts[0]->workerSessionId);
        $this->assertSame(1, $runtimeRunner->runCalls);
    }

    public function test_worker_command_db_path_prepares_runtime_handoffs_for_resolved_nodes(): void
    {
        $nodeA = $this->makeNode(1, 'db-node-a');
        $nodeB = $this->makeNode(2, 'db-node-b');

        $registry = new class implements PbxRegistryInterface {
            public function findById(int $id): PbxNode
            {
                throw new \BadMethodCallException('Registry should not be used in --db path.');
            }

            public function findBySlug(string $slug): PbxNode
            {
                throw new \BadMethodCallException('Registry should not be used in --db path.');
            }

            public function allActive(): array
            {
                return [];
            }

            public function allByCluster(string $cluster): array
            {
                return [];
            }

            public function allByTags(array $tags): array
            {
                return [];
            }

            public function allByProvider(string $providerCode): array
            {
                return [];
            }
        };

        $assignmentResolver = new class ($nodeA, $nodeB) implements WorkerAssignmentResolverInterface {
            public int $resolveNodesCalls = 0;
            public int $resolveForWorkerNameCalls = 0;

            public function __construct(
                private readonly PbxNode $nodeA,
                private readonly PbxNode $nodeB,
            ) {}

            public function resolveNodes(WorkerAssignment $assignment): array
            {
                $this->resolveNodesCalls++;

                return [];
            }

            public function resolveForWorkerName(string $workerName): array
            {
                $this->resolveForWorkerNameCalls++;

                return [$this->nodeA, $this->nodeB];
            }
        };

        $connectionResolver = new class implements ConnectionResolverInterface {
            /** @var list<string> */
            public array $resolvedNodeSlugs = [];

            public function resolveForNode(int $pbxNodeId): ConnectionContext
            {
                return $this->makeContext('unused');
            }

            public function resolveForSlug(string $slug): ConnectionContext
            {
                return $this->makeContext($slug);
            }

            public function resolveForPbxNode(PbxNode $node): ConnectionContext
            {
                $this->resolvedNodeSlugs[] = $node->slug;

                return $this->makeContext($node->slug, $node->id);
            }

            private function makeContext(string $slug, int $id = 1): ConnectionContext
            {
                return new ConnectionContext(
                    pbxNodeId: $id,
                    pbxNodeSlug: $slug,
                    providerCode: 'freeswitch',
                    host: '127.0.0.1',
                    port: 8021,
                    username: '',
                    resolvedPassword: 'ClueCon',
                    transport: 'tcp',
                    connectionProfileId: null,
                    connectionProfileName: 'default',
                );
            }
        };

        $connectionFactory = new class implements ConnectionFactoryInterface {
            public int $createCalls = 0;

            /** @var list<ConnectionContext> */
            public array $contexts = [];

            public function create(ConnectionContext $context): EslCoreConnectionHandle
            {
                $this->createCalls++;
                $this->contexts[] = $context;

                $commandFactory = new EslCoreCommandFactory();

                return new EslCoreConnectionHandle(
                    context: $context,
                    pipeline: (new EslCorePipelineFactory())->createPipeline(),
                    openingSequence: $commandFactory->buildOpeningSequence($context),
                    closingSequence: $commandFactory->buildClosingSequence(),
                    transportOpener: fn () => new InMemoryTransport(),
                );
            }
        };

        $runtimeRunner = new class implements RuntimeRunnerInterface {
            public int $runCalls = 0;

            public function run(RuntimeHandoffInterface $handoff): void
            {
                $this->runCalls++;
            }
        };

        $this->app->instance(PbxRegistryInterface::class, $registry);
        $this->app->instance(WorkerAssignmentResolverInterface::class, $assignmentResolver);
        $this->app->instance(ConnectionResolverInterface::class, $connectionResolver);
        $this->app->instance(ConnectionFactoryInterface::class, $connectionFactory);
        $this->app->instance(RuntimeRunnerInterface::class, $runtimeRunner);
        $this->app->instance(LoggerInterface::class, new NullLogger());

        $this->artisan('freeswitch:worker', [
            '--worker' => 'db-worker',
            '--db' => true,
        ])
            ->expectsOutputToContain('Starting worker [db-worker] from DB assignment (worker_assignments table) — 2 node(s).')
            ->expectsOutputToContain('Prepared runtime handoff for 2/2 node(s); runtime runner invoked for 2/2 node(s); push lifecycle observed for 0/2 node(s); live runtime observed for 0/2 node(s).')
            ->assertExitCode(0);

        $this->assertSame(0, $assignmentResolver->resolveNodesCalls);
        $this->assertSame(1, $assignmentResolver->resolveForWorkerNameCalls);
        $this->assertSame(2, $connectionFactory->createCalls);
        $this->assertCount(2, $connectionFactory->contexts);
        $this->assertSame('db-node-a', $connectionFactory->contexts[0]->pbxNodeSlug);
        $this->assertSame('db-node-b', $connectionFactory->contexts[1]->pbxNodeSlug);
        $this->assertNotNull($connectionFactory->contexts[0]->workerSessionId);
        $this->assertNotNull($connectionFactory->contexts[1]->workerSessionId);
        $this->assertSame(2, $runtimeRunner->runCalls);
    }

    private function makeNode(int $id, string $slug): PbxNode
    {
        return new PbxNode(
            id: $id,
            providerId: 1,
            providerCode: 'freeswitch',
            name: strtoupper($slug),
            slug: $slug,
            host: '127.0.0.1',
            port: 8021,
            username: '',
            passwordSecretRef: 'secret',
            transport: 'tcp',
            isActive: true,
        );
    }
}
