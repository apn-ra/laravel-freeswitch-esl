<?php

namespace ApnTalk\LaravelFreeswitchEsl\Tests\Support;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use React\Promise\PromiseInterface;

abstract class AsyncLoopTestCase extends TestCase
{
    protected LoopInterface $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loop = new StreamSelectLoop;
        Loop::set($this->loop);
    }

    protected function tearDown(): void
    {
        Loop::set(new StreamSelectLoop);

        parent::tearDown();
    }

    /**
     * @return mixed
     */
    protected function await(PromiseInterface $promise, float $timeoutSeconds = 1.0): mixed
    {
        $settled = false;
        $resolved = false;
        $value = null;
        $error = null;

        $promise->then(
            function (mixed $result) use (&$settled, &$resolved, &$value): void {
                $settled = true;
                $resolved = true;
                $value = $result;
                $this->loop->stop();
            },
            function (\Throwable $e) use (&$settled, &$error): void {
                $settled = true;
                $error = $e;
                $this->loop->stop();
            },
        );

        $timer = $this->loop->addTimer($timeoutSeconds, function () use (&$error, $timeoutSeconds): void {
            $error = new \RuntimeException(sprintf(
                'Timed out after %.2f seconds waiting for promise settlement',
                $timeoutSeconds,
            ));
            $this->loop->stop();
        });

        if (! $settled && $error === null) {
            $this->loop->run();
        }

        $this->loop->cancelTimer($timer);

        if ($error !== null) {
            throw $error;
        }

        if (! $settled) {
            throw new \RuntimeException('Promise did not settle.');
        }

        return $resolved ? $value : null;
    }

    protected function runLoopFor(float $seconds): void
    {
        $timer = $this->loop->addTimer($seconds, function (): void {
            $this->loop->stop();
        });

        $this->loop->run();
        $this->loop->cancelTimer($timer);
    }

    protected function waitUntil(callable $condition, float $timeoutSeconds = 1.0, float $pollSeconds = 0.005): void
    {
        if ($condition()) {
            return;
        }

        $error = null;

        $poller = $this->loop->addPeriodicTimer($pollSeconds, function ($timer) use ($condition): void {
            if (! $condition()) {
                return;
            }

            $this->loop->cancelTimer($timer);
            $this->loop->stop();
        });

        $timeout = $this->loop->addTimer($timeoutSeconds, function () use (&$error, $timeoutSeconds): void {
            $error = new \RuntimeException(sprintf(
                'Condition did not become true within %.2f seconds',
                $timeoutSeconds,
            ));
            $this->loop->stop();
        });

        $this->loop->run();
        $this->loop->cancelTimer($poller);
        $this->loop->cancelTimer($timeout);

        if ($error instanceof \Throwable) {
            throw $error;
        }
    }
}
