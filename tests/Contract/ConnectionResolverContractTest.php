<?php

namespace ApnTalk\LaravelFreeswitchEsl\Tests\Contract;

use ApnTalk\LaravelFreeswitchEsl\Contracts\ConnectionResolverInterface;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\Models\PbxConnectionProfile;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\Models\PbxNode;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\Models\PbxProvider;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\ConnectionContext;
use ApnTalk\LaravelFreeswitchEsl\Drivers\FreeSwitchDriver;
use ApnTalk\LaravelFreeswitchEsl\Tests\TestCase;

class ConnectionResolverContractTest extends TestCase
{
    public function test_resolve_for_slug_returns_fully_enriched_connection_context(): void
    {
        self::assertNotNull($this->app);

        $provider = $this->createProvider();
        $this->createDefaultProfile($provider);
        $node = $this->createNode($provider, [
            'slug' => 'primary-fs',
            'password_secret_ref' => 'ClueCon',
            'host' => '10.10.10.10',
            'port' => 8022,
        ]);

        /** @var ConnectionResolverInterface $resolver */
        $resolver = $this->app->make(ConnectionResolverInterface::class);
        $context = $resolver->resolveForSlug('primary-fs');

        $this->assertInstanceOf(ConnectionContext::class, $context);
        $this->assertSame((int) $node->getKey(), $context->pbxNodeId);
        $this->assertSame('primary-fs', $context->pbxNodeSlug);
        $this->assertSame('freeswitch', $context->providerCode);
        $this->assertSame('10.10.10.10', $context->host);
        $this->assertSame(8022, $context->port);
        $this->assertSame('ClueCon', $context->resolvedPassword);
        $this->assertSame('default', $context->connectionProfileName);
    }

    public function test_resolve_for_pbx_node_preserves_db_profile_identity_and_safe_log_context(): void
    {
        self::assertNotNull($this->app);

        $provider = $this->createProvider();
        $this->createDefaultProfile($provider, 'ops-default');
        $node = $this->createNode($provider, [
            'slug' => 'ops-fs',
            'password_secret_ref' => 'ENV_SECRET',
            'transport' => 'tls',
        ]);

        /** @var ConnectionResolverInterface $resolver */
        $resolver = $this->app->make(ConnectionResolverInterface::class);
        $context = $resolver->resolveForPbxNode($node->toValueObject());
        $log = $context->toLogContext();

        $this->assertSame('ops-default', $context->connectionProfileName);
        $this->assertSame('tls', $context->transport);
        $this->assertSame('ENV_SECRET', $context->resolvedPassword);
        $this->assertArrayNotHasKey('resolved_password', $log);
        $this->assertSame('ops-fs', $log['pbx_node_slug']);
        $this->assertSame('ops-default', $log['connection_profile']);
    }

    private function createProvider(string $code = 'freeswitch'): PbxProvider
    {
        return PbxProvider::query()->create([
            'code' => $code,
            'name' => ucfirst($code),
            'driver_class' => FreeSwitchDriver::class,
            'is_active' => true,
        ]);
    }

    private function createDefaultProfile(PbxProvider $provider, string $name = 'default'): PbxConnectionProfile
    {
        return PbxConnectionProfile::query()->create([
            'provider_id' => (int) $provider->getKey(),
            'name' => $name,
            'retry_policy_json' => ['max_attempts' => 4],
            'drain_policy_json' => ['timeout_ms' => 15000],
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createNode(PbxProvider $provider, array $overrides = []): PbxNode
    {
        return PbxNode::query()->create(array_merge([
            'provider_id' => (int) $provider->getKey(),
            'name' => 'Primary FS',
            'slug' => 'primary-fs',
            'host' => '127.0.0.1',
            'port' => 8021,
            'username' => '',
            'password_secret_ref' => 'ClueCon',
            'transport' => 'tcp',
            'is_active' => true,
        ], $overrides));
    }
}
