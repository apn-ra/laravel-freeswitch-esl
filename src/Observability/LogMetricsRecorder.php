<?php

namespace ApnTalk\LaravelFreeswitchEsl\Observability;

use ApnTalk\LaravelFreeswitchEsl\Contracts\MetricsRecorderInterface;
use Psr\Log\LoggerInterface;

final class LogMetricsRecorder implements MetricsRecorderInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $level = 'info',
    ) {}

    public function increment(string $name, int $value = 1, array $tags = []): void
    {
        $this->logger->log($this->level, 'FreeSwitch ESL metric recorded', [
            'metric_name' => $name,
            'metric_type' => 'counter',
            'metric_value' => $value,
            'metric_tags' => $tags,
        ]);
    }

    public function gauge(string $name, int|float $value, array $tags = []): void
    {
        $this->logger->log($this->level, 'FreeSwitch ESL metric recorded', [
            'metric_name' => $name,
            'metric_type' => 'gauge',
            'metric_value' => $value,
            'metric_tags' => $tags,
        ]);
    }
}
