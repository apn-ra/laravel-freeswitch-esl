<?php

namespace ApnTalk\LaravelFreeswitchEsl\Contracts\Upstream;

/**
 * Laravel-facing replay capture store contract.
 *
 * This interface is the integration point for apntalk/esl-replay (0.5.x).
 *
 * Boundary note:
 *   apntalk/esl-core already defines ReplayCaptureSinkInterface, which
 *   captures individual envelopes during an active protocol session.
 *   This store interface represents the higher-level Laravel-side abstraction
 *   for durable storage, retention, and retrieval — responsibilities that
 *   belong to apntalk/esl-replay, not esl-core.
 *
 * When apntalk/esl-replay is integrated (0.5.x), this stub should be
 * replaced by the canonical interface from that package. Until then, this
 * serves as the binding point for the Laravel service container.
 *
 * @internal This stub exists for 0.1.x–0.4.x development-phase isolation.
 *           Do not use this as a stable public API surface.
 *           Boundary: apntalk/esl-replay owns the canonical implementation.
 */
interface ReplayCaptureStoreInterface
{
    /**
     * Capture a replay envelope for durable storage.
     *
     * When apntalk/esl-replay is available, this method will accept
     * Apntalk\EslCore\Contracts\ReplayEnvelopeInterface instances instead
     * of raw arrays.
     *
     * @param  array<string, mixed>  $envelope  Replay envelope including event + runtime metadata
     */
    public function capture(array $envelope): void;

    /**
     * Return stored envelopes within a time window for the given partition key.
     *
     * Partition key format: "{provider_code}/{pbx_node_slug}/{worker_session_id}"
     *
     * @return array<int, array<string, mixed>>
     */
    public function retrieve(string $partitionKey, \DateTimeInterface $from, \DateTimeInterface $to): array;
}
