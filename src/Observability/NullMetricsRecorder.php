<?php

namespace ApnTalk\LaravelFreeswitchEsl\Observability;

use ApnTalk\LaravelFreeswitchEsl\Contracts\MetricsRecorderInterface;

final class NullMetricsRecorder implements MetricsRecorderInterface
{
    public function increment(string $name, int $value = 1, array $tags = []): void {}

    public function gauge(string $name, int|float $value, array $tags = []): void {}
}
