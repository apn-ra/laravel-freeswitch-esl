<?php

namespace ApnTalk\LaravelFreeswitchEsl\Tests\Integration\Console;

use ApnTalk\LaravelFreeswitchEsl\Contracts\PbxRegistryInterface;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\PbxNode;
use ApnTalk\LaravelFreeswitchEsl\Tests\TestCase;
use Illuminate\Contracts\Console\Kernel;

class FreeSwitchStatusCommandTest extends TestCase
{
    public function test_status_command_is_registered_with_the_console_kernel(): void
    {
        /** @var Kernel $kernel */
        $kernel = $this->app->make(Kernel::class);

        $this->assertArrayHasKey('freeswitch:status', $kernel->all());
    }

    public function test_status_command_renders_inventory_and_notes_runtime_recovery_visibility_scope(): void
    {
        $registry = new class implements PbxRegistryInterface
        {
            public function findById(int $id): PbxNode
            {
                return $this->node();
            }

            public function findBySlug(string $slug): PbxNode
            {
                return $this->node($slug);
            }

            public function allActive(): array
            {
                return [$this->node()];
            }

            public function allByCluster(string $cluster): array
            {
                return [$this->node()];
            }

            public function allByTags(array $tags): array
            {
                return [$this->node()];
            }

            public function allByProvider(string $providerCode): array
            {
                return [$this->node()];
            }

            private function node(string $slug = 'primary-fs'): PbxNode
            {
                return new PbxNode(
                    id: 1,
                    providerId: 1,
                    providerCode: 'freeswitch',
                    name: 'Primary FS',
                    slug: $slug,
                    host: '127.0.0.1',
                    port: 8021,
                    username: '',
                    passwordSecretRef: 'secret',
                    transport: 'tcp',
                    isActive: true,
                    cluster: 'us-east',
                    region: 'sg-1',
                    healthStatus: 'healthy',
                );
            }
        };

        $this->app->instance(PbxRegistryInterface::class, $registry);

        $this->artisan('freeswitch:status')
            ->expectsTable(
                ['ID', 'Slug', 'Provider', 'Host', 'Port', 'Cluster', 'Region', 'Health', 'Active'],
                [
                    ['1', 'primary-fs', 'freeswitch', '127.0.0.1', '8021', 'us-east', 'sg-1', 'healthy', 'yes'],
                ]
            )
            ->expectsOutputToContain('Replay-backed recovery posture is not shown in control-plane inventory output. Use worker runtime output for checkpoint/recovery visibility.')
            ->assertExitCode(0);
    }
}
