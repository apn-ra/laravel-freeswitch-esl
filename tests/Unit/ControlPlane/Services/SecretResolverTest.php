<?php

namespace ApnTalk\LaravelFreeswitchEsl\Tests\Unit\ControlPlane\Services;

use ApnTalk\LaravelFreeswitchEsl\Contracts\SecretResolverInterface;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\Services\SecretResolver;
use ApnTalk\LaravelFreeswitchEsl\Exceptions\ConnectionResolutionException;
use PHPUnit\Framework\TestCase;

class SecretResolverTest extends TestCase
{
    public function test_plaintext_mode_returns_ref_as_credential(): void
    {
        $resolver = new SecretResolver('plaintext');

        $this->assertSame('ClueCon', $resolver->resolve('ClueCon'));
    }

    public function test_env_mode_resolves_env_variable(): void
    {
        putenv('TEST_ESL_PASSWORD=my-secret');

        $resolver = new SecretResolver('env');

        $this->assertSame('my-secret', $resolver->resolve('TEST_ESL_PASSWORD'));

        putenv('TEST_ESL_PASSWORD');
    }

    public function test_env_mode_throws_when_variable_missing(): void
    {
        putenv('MISSING_ESL_PASSWORD'); // ensure not set

        $resolver = new SecretResolver('env');

        $this->expectException(ConnectionResolutionException::class);
        $this->expectExceptionMessage('MISSING_ESL_PASSWORD');

        $resolver->resolve('MISSING_ESL_PASSWORD');
    }

    public function test_unknown_mode_throws(): void
    {
        $resolver = new SecretResolver('vault');

        $this->expectException(ConnectionResolutionException::class);
        $this->expectExceptionMessage('vault');

        $resolver->resolve('some-ref');
    }

    public function test_custom_mode_without_resolver_throws(): void
    {
        $resolver = new SecretResolver('custom', null);

        $this->expectException(ConnectionResolutionException::class);
        $this->expectExceptionMessage('custom resolver');

        $resolver->resolve('some-ref');
    }

    public function test_custom_mode_delegates_to_custom_resolver(): void
    {
        $custom = new class implements SecretResolverInterface
        {
            public function resolve(string $secretRef): string
            {
                return 'resolved-'.$secretRef;
            }
        };

        $resolver = new SecretResolver('custom', $custom);

        $this->assertSame('resolved-my-ref', $resolver->resolve('my-ref'));
    }
}
