<?php

namespace ApnTalk\LaravelFreeswitchEsl\Tests\Unit\ControlPlane\ValueObjects;

use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\PbxNode;
use PHPUnit\Framework\TestCase;

class PbxNodeTest extends TestCase
{
    private function makeRecord(array $overrides = []): array
    {
        return array_merge([
            'id'                  => 1,
            'provider_id'         => 1,
            'provider_code'       => 'freeswitch',
            'name'                => 'Primary FS',
            'slug'                => 'primary-fs',
            'host'                => '10.0.0.1',
            'port'                => 8021,
            'username'            => '',
            'password_secret_ref' => 'ClueCon',
            'transport'           => 'tcp',
            'is_active'           => true,
            'region'              => null,
            'cluster'             => null,
            'tags_json'           => null,
            'settings_json'       => null,
            'health_status'       => 'unknown',
            'last_heartbeat_at'   => null,
        ], $overrides);
    }

    public function test_from_record_maps_all_fields(): void
    {
        $node = PbxNode::fromRecord($this->makeRecord());

        $this->assertSame(1, $node->id);
        $this->assertSame('freeswitch', $node->providerCode);
        $this->assertSame('primary-fs', $node->slug);
        $this->assertSame('10.0.0.1', $node->host);
        $this->assertSame(8021, $node->port);
        $this->assertTrue($node->isActive);
        $this->assertSame('tcp', $node->transport);
        $this->assertSame('unknown', $node->healthStatus);
        $this->assertNull($node->lastHeartbeatAt);
    }

    public function test_from_record_decodes_tags_json(): void
    {
        $node = PbxNode::fromRecord($this->makeRecord(['tags_json' => '["prod","us-east"]']));

        $this->assertSame(['prod', 'us-east'], $node->tags);
    }

    public function test_has_tag_returns_true_for_matching_tag(): void
    {
        $node = PbxNode::fromRecord($this->makeRecord(['tags_json' => '["prod","us-east"]']));

        $this->assertTrue($node->hasTag('prod'));
        $this->assertFalse($node->hasTag('staging'));
    }

    public function test_has_any_tag_returns_true_when_one_matches(): void
    {
        $node = PbxNode::fromRecord($this->makeRecord(['tags_json' => '["prod","us-east"]']));

        $this->assertTrue($node->hasAnyTag('staging', 'prod'));
        $this->assertFalse($node->hasAnyTag('dev', 'staging'));
    }

    public function test_runtime_identity_format(): void
    {
        $node = PbxNode::fromRecord($this->makeRecord());

        $this->assertStringContainsString('freeswitch', $node->runtimeIdentity());
        $this->assertStringContainsString('primary-fs', $node->runtimeIdentity());
        $this->assertStringContainsString('1', $node->runtimeIdentity());
    }

    public function test_from_record_parses_last_heartbeat_at(): void
    {
        $node = PbxNode::fromRecord($this->makeRecord([
            'last_heartbeat_at' => '2024-01-01T12:00:00+00:00',
        ]));

        $this->assertInstanceOf(\DateTimeImmutable::class, $node->lastHeartbeatAt);
    }

    public function test_from_record_handles_null_tags(): void
    {
        $node = PbxNode::fromRecord($this->makeRecord(['tags_json' => null]));

        $this->assertSame([], $node->tags);
    }

    public function test_immutability(): void
    {
        $node1 = PbxNode::fromRecord($this->makeRecord());
        $node2 = PbxNode::fromRecord($this->makeRecord(['id' => 2, 'slug' => 'secondary-fs']));

        $this->assertNotSame($node1->id, $node2->id);
        $this->assertNotSame($node1->slug, $node2->slug);
    }
}
