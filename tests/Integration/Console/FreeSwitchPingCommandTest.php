<?php

namespace ApnTalk\LaravelFreeswitchEsl\Tests\Integration\Console;

use ApnTalk\LaravelFreeswitchEsl\Contracts\ConnectionResolverInterface;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\ConnectionContext;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\PbxNode;
use ApnTalk\LaravelFreeswitchEsl\Tests\TestCase;
use Illuminate\Contracts\Console\Kernel;

class FreeSwitchPingCommandTest extends TestCase
{
    public function test_ping_command_is_registered_with_the_console_kernel(): void
    {
        /** @var Kernel $kernel */
        $kernel = $this->app->make(Kernel::class);

        $this->assertArrayHasKey('freeswitch:ping', $kernel->all());
    }

    public function test_ping_command_reports_resolved_connection_context(): void
    {
        $resolver = new class implements ConnectionResolverInterface {
            /** @var list<string> */
            public array $resolvedSlugs = [];

            public function resolveForNode(int $pbxNodeId): ConnectionContext
            {
                return $this->context('node-' . $pbxNodeId);
            }

            public function resolveForSlug(string $slug): ConnectionContext
            {
                $this->resolvedSlugs[] = $slug;

                return $this->context($slug);
            }

            public function resolveForPbxNode(PbxNode $node): ConnectionContext
            {
                return $this->context($node->slug);
            }

            private function context(string $slug): ConnectionContext
            {
                return new ConnectionContext(
                    pbxNodeId: 7,
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

        $this->app->instance(ConnectionResolverInterface::class, $resolver);

        $this->artisan('freeswitch:ping', ['--pbx' => 'primary-fs'])
            ->expectsOutput('Connection context resolved successfully.')
            ->assertExitCode(0);

        $this->assertSame(['primary-fs'], $resolver->resolvedSlugs);
    }
}
