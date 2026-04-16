<?php

namespace ApnTalk\LaravelFreeswitchEsl\Contracts\Upstream;

/**
 * Stub contract representing the replay capture store expected from apntalk/esl-replay.
 *
 * This package wires Laravel storage bindings and retention policies against
 * this interface. The canonical implementation belongs to apntalk/esl-replay.
 *
 * @internal Boundary: apntalk/esl-replay owns the canonical implementation.
 */
interface ReplayCaptureStoreInterface
{
    /**
     * Capture a raw ESL event for replay.
     *
     * @param  array<string, mixed>  $envelope  Replay envelope including event + metadata
     */
    public function capture(array $envelope): void;

    /**
     * Return stored envelopes within a time window for the given partition key.
     *
     * @return array<int, array<string, mixed>>
     */
    public function retrieve(string $partitionKey, \DateTimeInterface $from, \DateTimeInterface $to): array;
}
