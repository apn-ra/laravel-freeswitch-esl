<?php

namespace ApnTalk\LaravelFreeswitchEsl\Contracts\Upstream;

/**
 * Stub contract representing the event normalizer expected from apntalk/esl-core.
 *
 * The normalizer converts raw ESL frames/events into typed, provider-identity-
 * enriched representations suitable for application-level consumption.
 *
 * @internal Boundary: apntalk/esl-core owns the canonical implementation.
 */
interface EventNormalizerInterface
{
    /**
     * Normalize a raw event payload into a typed representation.
     *
     * @param  array<string, mixed>  $rawEvent  Raw ESL event headers/body
     * @return array<string, mixed>  Normalized event structure
     */
    public function normalize(array $rawEvent): array;
}
