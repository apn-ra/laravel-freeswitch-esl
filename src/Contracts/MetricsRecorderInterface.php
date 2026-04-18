<?php

namespace ApnTalk\LaravelFreeswitchEsl\Contracts;

interface MetricsRecorderInterface
{
    /**
     * @param  array<string, bool|float|int|string|null>  $tags
     */
    public function increment(string $name, int $value = 1, array $tags = []): void;

    /**
     * @param  array<string, bool|float|int|string|null>  $tags
     */
    public function gauge(string $name, int|float $value, array $tags = []): void;
}
