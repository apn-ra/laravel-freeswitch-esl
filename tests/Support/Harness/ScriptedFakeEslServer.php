<?php

namespace ApnTalk\LaravelFreeswitchEsl\Tests\Support\Harness;

use React\EventLoop\LoopInterface;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;

final class ScriptedFakeEslServer
{
    private readonly SocketServer $server;

    private ?ConnectionInterface $activeConnection = null;

    /** @var list<ConnectionInterface> */
    private array $connections = [];

    /** @var list<callable(ConnectionInterface, string): void> */
    private array $commandHandlers = [];

    /** @var list<string> */
    private array $receivedCommands = [];

    /** @var list<list<string>> */
    private array $receivedCommandsByConnection = [];

    public function __construct(
        private readonly LoopInterface $loop,
        bool $autoAuthRequest = true,
    ) {
        $this->server = new SocketServer('127.0.0.1:0', [], $this->loop);
        $this->server->on('connection', function (ConnectionInterface $connection) use ($autoAuthRequest): void {
            $this->activeConnection = $connection;
            $this->connections[] = $connection;
            $connectionIndex = array_key_last($this->connections);
            \assert(is_int($connectionIndex));
            $this->receivedCommandsByConnection[$connectionIndex] = [];

            if ($autoAuthRequest) {
                $this->writeFrame($connection, "Content-Type: auth/request\n\n");
            }

            $buffer = '';
            $connection->on('data', function (string $chunk) use ($connection, $connectionIndex, &$buffer): void {
                $buffer .= $chunk;

                while (($pos = strpos($buffer, "\n\n")) !== false) {
                    $command = trim(substr($buffer, 0, $pos));
                    $buffer = substr($buffer, $pos + 2);

                    if ($command === '') {
                        continue;
                    }

                    $this->receivedCommands[] = $command;
                    $this->receivedCommandsByConnection[$connectionIndex][] = $command;

                    if ($this->commandHandlers !== []) {
                        $handler = array_shift($this->commandHandlers);
                        $handler($connection, $command);
                    }
                }
            });

            $connection->on('close', function () use ($connection): void {
                if ($this->activeConnection === $connection) {
                    $this->activeConnection = null;
                }
            });
        });
    }

    public function port(): int
    {
        $port = parse_url($this->address(), PHP_URL_PORT);

        if (! is_int($port)) {
            throw new \RuntimeException('Unable to determine fake-server port.');
        }

        return $port;
    }

    public function queueCommandHandler(callable $handler): void
    {
        $this->commandHandlers[] = $handler;
    }

    /**
     * @return list<string>
     */
    public function receivedCommands(): array
    {
        return $this->receivedCommands;
    }

    /**
     * @return list<list<string>>
     */
    public function receivedCommandsByConnection(): array
    {
        return $this->receivedCommandsByConnection;
    }

    public function connectionCount(): int
    {
        return count($this->connections);
    }

    public function closeActiveConnection(): void
    {
        $this->requireActiveConnection()->close();
    }

    public function writeCommandReply(ConnectionInterface $connection, string $replyText): void
    {
        $this->writeFrame($connection, "Content-Type: command/reply\nReply-Text: {$replyText}\n\n");
    }

    public function emitPlainEvent(array $headers, string $body = ''): void
    {
        $eventHeaderLines = [];

        foreach ($headers as $name => $value) {
            $eventHeaderLines[] = sprintf('%s: %s', $name, rawurlencode((string) $value));
        }

        $eventPayload = implode("\n", $eventHeaderLines);

        if ($body !== '') {
            $eventPayload .= "\n\n".$body;
        }

        $this->writeFrame(
            $this->requireActiveConnection(),
            sprintf(
                "Content-Type: text/event-plain\nContent-Length: %d\n\n%s",
                strlen($eventPayload),
                $eventPayload,
            ),
        );
    }

    public function close(): void
    {
        $this->server->close();
    }

    private function address(): string
    {
        $address = $this->server->getAddress();

        if (! is_string($address)) {
            throw new \RuntimeException('Fake server has no listening address.');
        }

        return $address;
    }

    private function writeFrame(ConnectionInterface $connection, string $frame): void
    {
        $connection->write($frame);
    }

    private function requireActiveConnection(): ConnectionInterface
    {
        if ($this->activeConnection === null) {
            throw new \RuntimeException('No active fake-server connection available.');
        }

        return $this->activeConnection;
    }
}
