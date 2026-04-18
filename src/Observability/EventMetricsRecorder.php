<?php

namespace ApnTalk\LaravelFreeswitchEsl\Observability;

use ApnTalk\LaravelFreeswitchEsl\Contracts\MetricsRecorderInterface;
use ApnTalk\LaravelFreeswitchEsl\Events\MetricsRecorded;
use Illuminate\Contracts\Events\Dispatcher;

final class EventMetricsRecorder implements MetricsRecorderInterface
{
    public function __construct(
        private readonly Dispatcher $events,
    ) {}

    public function increment(string $name, int $value = 1, array $tags = []): void
    {
        $this->events->dispatch(new MetricsRecorded(
            name: $name,
            type: 'counter',
            value: $value,
            tags: $tags,
        ));
    }

    public function gauge(string $name, int|float $value, array $tags = []): void
    {
        $this->events->dispatch(new MetricsRecorded(
            name: $name,
            type: 'gauge',
            value: $value,
            tags: $tags,
        ));
    }
}
