<?php

namespace ApnTalk\LaravelFreeswitchEsl\Tests\Unit\Drivers;

use Apntalk\EslCore\Capabilities\Capability;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\ConnectionProfile;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\PbxNode;
use ApnTalk\LaravelFreeswitchEsl\Drivers\FreeSwitchDriver;
use PHPUnit\Framework\TestCase;

class FreeSwitchDriverTest extends TestCase
{
    private function makeNode(array $overrides = []): PbxNode
    {
        $defaults = [
            'id' => 1,
            'providerId' => 1,
            'providerCode' => 'freeswitch',
            'name' => 'Primary',
            'slug' => 'primary-fs',
            'host' => '10.0.0.1',
            'port' => 8021,
            'username' => '',
            'passwordSecretRef' => 'ClueCon',
            'transport' => 'tcp',
            'isActive' => true,
        ];

        $params = array_merge($defaults, $overrides);

        return new PbxNode(
            id: $params['id'],
            providerId: $params['providerId'],
            providerCode: $params['providerCode'],
            name: $params['name'],
            slug: $params['slug'],
            host: $params['host'],
            port: $params['port'],
            username: $params['username'],
            passwordSecretRef: $params['passwordSecretRef'],
            transport: $params['transport'],
            isActive: $params['isActive'],
        );
    }

    private function makeProfile(): ConnectionProfile
    {
        return ConnectionProfile::fromConfigDefaults(
            retryDefaults: ['max_attempts' => 5],
            drainDefaults: ['timeout_ms' => 30000],
        );
    }

    public function test_provider_code_is_freeswitch(): void
    {
        $driver = new FreeSwitchDriver;

        $this->assertSame('freeswitch', $driver->providerCode());
    }

    public function test_build_connection_context_maps_node_fields(): void
    {
        $driver = new FreeSwitchDriver;
        $context = $driver->buildConnectionContext($this->makeNode(), $this->makeProfile());

        $this->assertSame(1, $context->pbxNodeId);
        $this->assertSame('primary-fs', $context->pbxNodeSlug);
        $this->assertSame('freeswitch', $context->providerCode);
        $this->assertSame('10.0.0.1', $context->host);
        $this->assertSame(8021, $context->port);
        $this->assertSame('tcp', $context->transport);
    }

    public function test_build_connection_context_resolved_password_is_empty(): void
    {
        // Password is intentionally empty here — ConnectionResolver injects it
        $driver = new FreeSwitchDriver;
        $context = $driver->buildConnectionContext($this->makeNode(), $this->makeProfile());

        $this->assertSame('', $context->resolvedPassword);
    }

    public function test_build_connection_context_includes_driver_parameters(): void
    {
        $driver = new FreeSwitchDriver;
        $context = $driver->buildConnectionContext($this->makeNode(), $this->makeProfile());

        $this->assertArrayHasKey('esl_host', $context->driverParameters);
        $this->assertArrayHasKey('esl_port', $context->driverParameters);
        $this->assertArrayHasKey('retry', $context->driverParameters);
    }

    public function test_supports_capability(): void
    {
        $driver = new FreeSwitchDriver;

        // FreeSWITCH ESL supports these esl-core Capability values
        $this->assertTrue($driver->supportsCapability(Capability::Auth->value));
        $this->assertTrue($driver->supportsCapability(Capability::ApiCommand->value));
        $this->assertTrue($driver->supportsCapability(Capability::BgapiCommand->value));
        $this->assertTrue($driver->supportsCapability(Capability::EventSubscription->value));
        $this->assertTrue($driver->supportsCapability(Capability::EventPlainDecoding->value));
        $this->assertTrue($driver->supportsCapability(Capability::EventJsonDecoding->value));
        $this->assertTrue($driver->supportsCapability(Capability::NormalizedEvents->value));

        // Not supported by this driver
        $this->assertFalse($driver->supportsCapability('sip'));
        $this->assertFalse($driver->supportsCapability('unknown-capability'));
    }

    public function test_profile_name_passed_to_context(): void
    {
        $driver = new FreeSwitchDriver;
        $profile = ConnectionProfile::fromConfigDefaults();
        $context = $driver->buildConnectionContext($this->makeNode(), $profile);

        $this->assertSame('default', $context->connectionProfileName);
    }
}
