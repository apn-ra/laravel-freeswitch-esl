<?php

namespace ApnTalk\LaravelFreeswitchEsl\Drivers;

use Apntalk\EslCore\Capabilities\Capability;
use ApnTalk\LaravelFreeswitchEsl\Contracts\ProviderDriverInterface;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\ConnectionContext;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\ConnectionProfile;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\PbxNode;

/**
 * FreeSWITCH provider driver.
 *
 * Builds ConnectionContext for FreeSWITCH ESL endpoints.
 *
 * Boundary note:
 *   This driver only performs parameter/context construction. It does NOT
 *   open sockets, parse ESL frames, or manage connection lifecycle. Those
 *   responsibilities belong in apntalk/esl-core (protocol) and
 *   apntalk/esl-react (async runtime).
 *
 * When apntalk/esl-react is available, the ConnectionFactory implementation
 * will use the ConnectionContext produced here to bootstrap the actual runtime.
 *
 * Capability strings match apntalk/esl-core Capability enum values.
 * Use Capability::xxx->value when calling supportsCapability() from typed code.
 */
class FreeSwitchDriver implements ProviderDriverInterface
{
    /**
     * Capabilities supported by the FreeSWITCH ESL connection.
     *
     * These correspond to Capability enum values from apntalk/esl-core.
     * FreeSWITCH ESL supports auth, synchronous API, background API,
     * event subscriptions (plain/json/xml), and normalized events.
     *
     * @var list<string>
     */
    private const SUPPORTED_CAPABILITIES = [
        Capability::Auth->value,
        Capability::ApiCommand->value,
        Capability::BgapiCommand->value,
        Capability::EventSubscription->value,
        Capability::EventPlainDecoding->value,
        Capability::EventJsonDecoding->value,
        Capability::NormalizedEvents->value,
    ];

    public function providerCode(): string
    {
        return 'freeswitch';
    }

    public function buildConnectionContext(PbxNode $node, ConnectionProfile $profile): ConnectionContext
    {
        return new ConnectionContext(
            pbxNodeId: $node->id,
            pbxNodeSlug: $node->slug,
            providerCode: $this->providerCode(),
            host: $node->host,
            port: $node->port,
            username: $node->username,
            resolvedPassword: '', // populated by ConnectionResolver after SecretResolver runs
            transport: $node->transport,
            connectionProfileId: $profile->id,
            connectionProfileName: $profile->name,
            driverParameters: $this->buildDriverParameters($node, $profile),
        );
    }

    /**
     * Check whether this driver supports a given capability.
     *
     * Pass Capability::xxx->value from apntalk/esl-core for typed lookups:
     *   $driver->supportsCapability(Capability::BgapiCommand->value)
     */
    public function supportsCapability(string $capability): bool
    {
        return in_array($capability, self::SUPPORTED_CAPABILITIES, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDriverParameters(PbxNode $node, ConnectionProfile $profile): array
    {
        $params = [
            'esl_host'      => $node->host,
            'esl_port'      => $node->port,
            'esl_transport' => $node->transport,
            'esl_username'  => $node->username,
        ];

        // Merge subscription and worker profile settings from the connection profile
        if (! empty($profile->subscriptionProfile)) {
            $params['subscription'] = $profile->subscriptionProfile;
        }

        if (! empty($profile->retryPolicy)) {
            $params['retry'] = $profile->retryPolicy;
        }

        if (! empty($profile->workerProfile)) {
            $params['worker'] = $profile->workerProfile;
        }

        return $params;
    }
}
