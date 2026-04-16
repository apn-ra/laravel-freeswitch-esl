<?php

namespace ApnTalk\LaravelFreeswitchEsl\Contracts;

use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\ConnectionContext;

/**
 * Creates a runtime handoff bundle from a resolved ConnectionContext.
 *
 * Concrete implementations assemble the Laravel-owned connection seam for the
 * current integration stage. The preferred adapter boundary is
 * RuntimeHandoffInterface, even though this package currently ships
 * EslCoreConnectionHandle as the default implementation.
 *
 * This interface is owned by this Laravel package.
 * Long-lived runtime behavior (reconnect, subscription lifecycle, supervision)
 * remains owned by apntalk/esl-react.
 */
interface ConnectionFactoryInterface
{
    /**
     * Create a connection/runtime handoff bundle for the given context.
     */
    public function create(ConnectionContext $context): RuntimeHandoffInterface;
}
