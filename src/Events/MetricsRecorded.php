<?php

namespace ApnTalk\LaravelFreeswitchEsl\Events;

final class MetricsRecorded
{
    public const SCHEMA_VERSION = '1.0';

    /**
     * @param  array<string, bool|float|int|string|null>  $tags
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly int|float $value,
        public readonly array $tags = [],
        public readonly string $schemaVersion = self::SCHEMA_VERSION,
    ) {}
}
