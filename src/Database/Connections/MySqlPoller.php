<?php

declare(strict_types=1);

namespace Atria\Database\Connections;

use Atria\Async\EventLoop;
use Atria\Async\Suspension;
use mysqli;

/**
 * Waits for asynchronous mysqli queries on the event loop.
 *
 * mysqli exposes no socket for stream_select(), so one timer polls every
 * waiting connection with mysqli::poll() while at least one query is pending.
 *
 * @internal Shared by the links of one MySqlConnection.
 */
final class MySqlPoller
{
    /** @var array<int, array{mysqli, Suspension}> By object id of the connection. */
    private array $waiting = [];

    private ?string $timer = null;

    public function __construct(
        private readonly EventLoop $loop,
        private readonly float $interval = 0.001,
    ) {}

    /**
     * Suspends the current task until the connection's async query is ready.
     */
    public function wait(mysqli $connection): void
    {
        $id = spl_object_id($connection);
        $suspension = $this->loop->getSuspension();

        $this->waiting[$id] = [$connection, $suspension];
        $this->schedule();

        try {
            $suspension->suspend();
        } finally {
            unset($this->waiting[$id]);
        }
    }

    /**
     * Forgets pending waits; called between requests, with the event loop reset.
     */
    public function reset(): void
    {
        if ($this->timer !== null) {
            $this->loop->cancel($this->timer);
        }

        $this->timer = null;
        $this->waiting = [];
    }

    /**
     * Connections mysqli::poll() left in its by-reference lists.
     *
     * @return list<mysqli>
     */
    private static function connectionsIn(mixed ...$lists): array
    {
        $connections = [];

        foreach ($lists as $list) {
            foreach (is_array($list) ? $list : [] as $connection) {
                if ($connection instanceof mysqli) {
                    $connections[] = $connection;
                }
            }
        }

        return $connections;
    }

    private function schedule(): void
    {
        $this->timer ??= $this->loop->delay($this->interval, fn() => $this->poll());
    }

    private function poll(): void
    {
        $this->timer = null;

        if ($this->waiting === []) {
            return;
        }

        $connections = array_column($this->waiting, 0);
        $read = $connections;
        $error = $connections;
        $reject = $connections;

        if (mysqli::poll($read, $error, $reject, 0, 0) === false) {
            $read = $connections;
        }

        foreach (self::connectionsIn($read, $error, $reject) as $connection) {
            $id = spl_object_id($connection);
            $entry = $this->waiting[$id] ?? null;

            if ($entry === null) {
                continue;
            }

            unset($this->waiting[$id]);
            $entry[1]->resume();
        }

        if ($this->waiting !== []) {
            $this->schedule();
        }
    }
}
