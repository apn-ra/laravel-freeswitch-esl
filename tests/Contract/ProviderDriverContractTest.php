<?php

namespace ApnTalk\LaravelFreeswitchEsl\Tests\Contract;

use ApnTalk\LaravelFreeswitchEsl\Contracts\ProviderDriverInterface;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\ConnectionContext;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\ConnectionProfile;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\PbxNode;
use ApnTalk\LaravelFreeswitchEsl\Drivers\FreeSwitchDriver;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for ProviderDriverInterface.
 *
 * Each concrete driver must satisfy these invariants regardless of its
 * internal implementation. When additional drivers are added, they should
 * be covered here by the same contract assertions.
 *
 * These tests verify the PUBLIC CONTRACT only — not internal behavior.
 */
class ProviderDriverContractTest extends TestCase
{
    /**
     * @return array<string, array{ProviderDriverInterface}>
     */
    public static function driverProvider(): array
    {
        return [
            'FreeSwitchDriver' => [new FreeSwitchDriver()],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('driverProvider')]
    public function test_provider_code_is_non_empty_string(ProviderDriverInterface $driver): void
    {
        $this->assertNotEmpty($driver->providerCode());
        $this->assertIsString($driver->providerCode());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('driverProvider')]
    public function test_provider_code_contains_no_whitespace(ProviderDriverInterface $driver): void
    {
        $this->assertMatchesRegularExpression('/^\S+$/', $driver->providerCode());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('driverProvider')]
    public function test_build_connection_context_returns_context_instance(ProviderDriverInterface $driver): void
    {
        $context = $driver->buildConnectionContext($this->makeNode(), $this->makeProfile());

        $this->assertInstanceOf(ConnectionContext::class, $context);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('driverProvider')]
    public function test_build_connection_context_preserves_node_identity(ProviderDriverInterface $driver): void
    {
        $node = $this->makeNode(id: 42, slug: 'contract-test-node');
        $context = $driver->buildConnectionContext($node, $this->makeProfile());

        $this->assertSame(42, $context->pbxNodeId);
        $this->assertSame('contract-test-node', $context->pbxNodeSlug);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('driverProvider')]
    public function test_build_connection_context_preserves_provider_code(ProviderDriverInterface $driver): void
    {
        $context = $driver->buildConnectionContext($this->makeNode(), $this->makeProfile());

        $this->assertSame($driver->providerCode(), $context->providerCode);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('driverProvider')]
    public function test_build_connection_context_preserves_connection_coordinates(ProviderDriverInterface $driver): void
    {
        $node = $this->makeNode(host: '192.168.1.100', port: 8022, transport: 'tls');
        $context = $driver->buildConnectionContext($node, $this->makeProfile());

        $this->assertSame('192.168.1.100', $context->host);
        $this->assertSame(8022, $context->port);
        $this->assertSame('tls', $context->transport);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('driverProvider')]
    public function test_build_connection_context_does_not_inject_credential(ProviderDriverInterface $driver): void
    {
        // Drivers must NOT call the secret resolver — ConnectionResolver does that.
        // The resolved_password must be empty from the driver's output.
        $context = $driver->buildConnectionContext($this->makeNode(), $this->makeProfile());

        $this->assertSame(
            '',
            $context->resolvedPassword,
            'Driver must return empty resolvedPassword; ConnectionResolver injects the credential.'
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('driverProvider')]
    public function test_supports_capability_returns_bool(ProviderDriverInterface $driver): void
    {
        $this->assertIsBool($driver->supportsCapability('any-capability'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('driverProvider')]
    public function test_to_log_context_omits_credential(ProviderDriverInterface $driver): void
    {
        $context = $driver->buildConnectionContext($this->makeNode(), $this->makeProfile());
        $log = $context->toLogContext();

        $this->assertArrayNotHasKey('resolved_password', $log);
        $this->assertArrayNotHasKey('resolvedPassword', $log);
        $this->assertArrayHasKey('pbx_node_id', $log);
        $this->assertArrayHasKey('pbx_node_slug', $log);
        $this->assertArrayHasKey('provider_code', $log);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeNode(
        int $id = 1,
        string $slug = 'test-node',
        string $host = '10.0.0.1',
        int $port = 8021,
        string $transport = 'tcp',
    ): PbxNode {
        return new PbxNode(
            id: $id,
            providerId: 1,
            providerCode: 'freeswitch',
            name: 'Test Node',
            slug: $slug,
            host: $host,
            port: $port,
            username: '',
            passwordSecretRef: 'secret-ref',
            transport: $transport,
            isActive: true,
        );
    }

    private function makeProfile(): ConnectionProfile
    {
        return ConnectionProfile::fromConfigDefaults(
            retryDefaults: ['max_attempts' => 3],
            drainDefaults: ['timeout_ms' => 10000],
        );
    }
}
