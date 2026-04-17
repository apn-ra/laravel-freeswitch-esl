<?php

namespace ApnTalk\LaravelFreeswitchEsl\Contracts;

use ApnTalk\LaravelFreeswitchEsl\Exceptions\ConnectionResolutionException;

/**
 * Resolves a secret credential from a secret reference string.
 *
 * The secret_ref stored on a PbxNode may be a literal password, an
 * environment variable name, a Vault path, or any other reference
 * depending on the configured resolver mode.
 *
 * This keeps raw credentials out of the DB and decoupled from the
 * specific secret-storage backend.
 */
interface SecretResolverInterface
{
    /**
     * Resolve the plaintext credential for the given secret reference.
     *
     * @throws ConnectionResolutionException
     */
    public function resolve(string $secretRef): string;
}
