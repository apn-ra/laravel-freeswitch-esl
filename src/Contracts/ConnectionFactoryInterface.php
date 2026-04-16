<?php

namespace ApnTalk\LaravelFreeswitchEsl\Contracts;

use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\ConnectionContext;
use ApnTalk\LaravelFreeswitchEsl\Integration\EslCoreConnectionHandle;

/**
 * Creates a runtime handoff handle from a resolved ConnectionContext.
 *
 * Concrete implementations assemble the Laravel-owned connection seam for the
 * current integration stage. In 0.2.x, that means packaging a resolved context
 * together with the esl-core protocol primitives the future runtime will use.
 *
 * This interface is owned by this Laravel package.
 * Long-lived runtime behavior (reconnect, subscription lifecycle, supervision)
 * remains owned by apntalk/esl-react.
 */
interface ConnectionFactoryInterface
{
    /**
     * Create a connection/runtime handoff handle for the given context.
     *
     * In the current 0.2.x posture, this returns the package-owned
     * EslCoreConnectionHandle used by worker/runtime handoff scaffolding.
     */
    public function create(ConnectionContext $context): EslCoreConnectionHandle;
}
