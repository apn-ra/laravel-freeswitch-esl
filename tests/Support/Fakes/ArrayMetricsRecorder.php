<?php

namespace ApnTalk\LaravelFreeswitchEsl\Tests\Support\Fakes;

use ApnTalk\LaravelFreeswitchEsl\Contracts\MetricsRecorderInterface;

final class ArrayMetricsRecorder implements MetricsRecorderInterface
{
    /**
     * @var list<array{name: string, value: int, tags: array<string, bool|float|int|string|null>}>
     */
    public array $increments = [];

    /**
     * @var list<array{name: string, value: float|int, tags: array<string, bool|float|int|string|null>}>
     */
    public array $gauges = [];

    public function increment(string $name, int $value = 1, array $tags = []): void
    {
        $this->increments[] = [
            'name' => $name,
            'value' => $value,
            'tags' => $tags,
        ];
    }

    public function gauge(string $name, int|float $value, array $tags = []): void
    {
        $this->gauges[] = [
            'name' => $name,
            'value' => $value,
            'tags' => $tags,
        ];
    }
}
