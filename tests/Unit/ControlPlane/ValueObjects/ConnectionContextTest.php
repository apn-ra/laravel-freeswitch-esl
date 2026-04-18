<?php

namespace ApnTalk\LaravelFreeswitchEsl\Tests\Unit\ControlPlane\ValueObjects;

use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\ConnectionContext;
use PHPUnit\Framework\TestCase;

class ConnectionContextTest extends TestCase
{
    public function test_to_log_context_omits_resolved_password_and_preserves_runtime_identity(): void
    {
        $context = $this->makeContext();

        $log = $context->toLogContext();

        $this->assertArrayNotHasKey('resolved_password', $log);
        $this->assertArrayNotHasKey('resolvedPassword', $log);
        $this->assertSame(7, $log['pbx_node_id']);
        $this->assertSame('primary-fs', $log['pbx_node_slug']);
        $this->assertSame('freeswitch', $log['provider_code']);
        $this->assertSame('default', $log['connection_profile']);
        $this->assertNull($log['worker_session_id']);
    }

    public function test_with_worker_session_returns_new_instance_without_mutating_original(): void
    {
        $context = $this->makeContext();

        $updated = $context->withWorkerSession('worker-session-1');

        $this->assertNull($context->workerSessionId);
        $this->assertSame('worker-session-1', $updated->workerSessionId);
        $this->assertSame($context->resolvedPassword, $updated->resolvedPassword);
        $this->assertSame($context->driverParameters, $updated->driverParameters);
        $this->assertNotSame($context, $updated);
    }

    private function makeContext(): ConnectionContext
    {
        return new ConnectionContext(
            pbxNodeId: 7,
            pbxNodeSlug: 'primary-fs',
            providerCode: 'freeswitch',
            host: '10.0.0.7',
            port: 8021,
            username: '',
            resolvedPassword: 'ClueCon',
            transport: 'tcp',
            connectionProfileId: 3,
            connectionProfileName: 'default',
            driverParameters: ['tls' => false],
        );
    }
}
