<?php

namespace ApnTalk\LaravelFreeswitchEsl\ControlPlane\Services;

use ApnTalk\LaravelFreeswitchEsl\Contracts\SecretResolverInterface;
use ApnTalk\LaravelFreeswitchEsl\Exceptions\ConnectionResolutionException;

/**
 * Resolves PBX node credentials from a configured secret-resolution mode.
 *
 * Modes:
 *   plaintext — secret_ref is the literal credential
 *   env       — secret_ref is an environment variable name
 *   custom    — delegates to a custom SecretResolverInterface implementation
 *
 * Vault and other backends are future work; extend by adding modes here or
 * by injecting a custom resolver class through config.
 */
class SecretResolver implements SecretResolverInterface
{
    public function __construct(
        private readonly string $mode,
        private readonly ?SecretResolverInterface $customResolver = null,
    ) {}

    public function resolve(string $secretRef): string
    {
        return match ($this->mode) {
            'plaintext' => $secretRef,
            'env'       => $this->resolveFromEnv($secretRef),
            'custom'    => $this->resolveFromCustom($secretRef),
            default     => throw ConnectionResolutionException::unknownSecretMode($this->mode),
        };
    }

    private function resolveFromEnv(string $secretRef): string
    {
        $value = getenv($secretRef);

        if ($value === false) {
            throw ConnectionResolutionException::envSecretNotFound($secretRef);
        }

        return $value;
    }

    private function resolveFromCustom(string $secretRef): string
    {
        if ($this->customResolver === null) {
            throw ConnectionResolutionException::customResolverNotConfigured();
        }

        return $this->customResolver->resolve($secretRef);
    }
}
