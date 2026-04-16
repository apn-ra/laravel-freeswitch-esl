<?php

namespace ApnTalk\LaravelFreeswitchEsl\Contracts;

use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\ConnectionContext;

/**
 * Creates live ESL client/transport instances from a resolved ConnectionContext.
 *
 * Concrete implementations delegate to apntalk/esl-react or another runtime
 * library. This interface shields the Laravel control plane from runtime
 * library internals.
 *
 * This interface is owned by this Laravel package.
 * The runtime behavior (reconnect, subscription lifecycle) is owned by
 * apntalk/esl-react.
 */
interface ConnectionFactoryInterface
{
    /**
     * Create a connection handle for the given context.
     *
     * Returns an opaque handle whose type is defined by the concrete driver
     * implementation. The control plane does not inspect the handle directly;
     * it passes it to WorkerRuntime and CommandDispatcher adapters.
     *
     * @return mixed
     */
    public function create(ConnectionContext $context): mixed;
}
