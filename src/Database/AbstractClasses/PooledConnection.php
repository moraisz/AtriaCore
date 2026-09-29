<?php

declare(strict_types=1);

namespace Atria\Database\AbstractClasses;

use Atria\Async\EventLoop;
use Atria\Async\Suspension;
use Atria\Database\Contracts\ConnectionLink;
use Atria\Database\Contracts\DatabaseConnection;
use Atria\Database\Exceptions\QueryException;
use Atria\Database\Result;
use Atria\System\Contracts\Resettable;
use Fiber;
use LogicException;
use RuntimeException;
use WeakMap;

/**
 * Database connection backed by a per-worker pool of ConnectionLinks.
 *
 * Outside Async::run() it behaves like one persistent connection. Inside
 * Async::concurrently() tasks, each task borrows its own link, up to `pool_size`, so
 * independent queries run at the same time. A transaction pins its link to
 * the task (or main flow) that opened it.
 *
 * Links survive across requests in worker mode. reset() rolls back a leaked
 * transaction, closes links left mid-query and recycles links once
 * `max_lifetime` (seconds, 0 = never) expires. A statement that fails because
 * the server dropped the connection is retried once on a fresh link, unless it
 * ran inside a transaction.
 */
abstract class PooledConnection implements DatabaseConnection, Resettable
{
    private const DEFAULT_POOL_SIZE = 4;

    /** @var list<ConnectionLink> */
    private array $idle = [];

    /** @var array<int, ConnectionLink> Links in use, by object id. */
    private array $borrowed = [];

    /** @var list<Suspension> Callers waiting for a link while the pool is exhausted. */
    private array $waiters = [];

    /** Links being opened right now. */
    private int $connecting = 0;

    private ?ConnectionLink $mainTransaction = null;

    /** @var WeakMap<object, ConnectionLink> Transactions opened inside Fibers, by Fiber. */
    private WeakMap $fiberTransactions;

    /** Link of the last statement run by the main flow, for lastInsertId(). */
    private ?ConnectionLink $mainLastUsed = null;

    /** @var WeakMap<object, ConnectionLink> Link of the last statement run by each Fiber. */
    private WeakMap $fiberLastUsed;

    protected readonly EventLoop $loop;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(protected array $config = [], ?EventLoop $loop = null)
    {
        $extension = $this->requiredExtension();

        if ($extension !== null && !extension_loaded($extension)) {
            throw new RuntimeException(static::class . " requires the {$extension} PHP extension.");
        }

        $this->loop = $loop ?? EventLoop::instance();
        $this->fiberTransactions = new WeakMap();
        $this->fiberLastUsed = new WeakMap();
    }

    /**
     * PHP extension the driver needs, checked when the connection is created.
     */
    abstract protected function requiredExtension(): ?string;

    /**
     * Opens a new physical connection.
     */
    abstract protected function openLink(): ConnectionLink;

    /**
     * Rewrites the query into the driver's placeholder syntax, if needed.
     */
    protected function prepareSql(string $query): string
    {
        return $query;
    }

    public function connect(): void
    {
        if ($this->openLinks() === 0) {
            $this->release($this->acquire());
        }
    }

    public function disconnect(): void
    {
        foreach ([...$this->idle, ...$this->borrowed] as $link) {
            $link->close();
        }

        $this->idle = [];
        $this->borrowed = [];
        $this->waiters = [];
        $this->mainTransaction = null;
        $this->fiberTransactions = new WeakMap();
        $this->forgetLastUsed();
    }

    /**
     * Returns the link pinned to the current transaction, if any.
     */
    public function getConnection(): mixed
    {
        return $this->transactionLink();
    }

    public function isConnected(): bool
    {
        return $this->openLinks() > 0;
    }

    public function beginTransaction(): void
    {
        if ($this->transactionLink() !== null) {
            throw new QueryException('There is already an active transaction', '25001');
        }

        $link = $this->acquire();

        try {
            $link->begin();
        } catch (\Throwable $e) {
            $this->discardOrRelease($link);
            throw $e;
        }

        $this->pin($link);
        $this->rememberLastUsed($link);
    }

    public function commit(): void
    {
        $this->finishTransaction(true);
    }

    public function rollback(): void
    {
        $this->finishTransaction(false);
    }

    public function inTransaction(): bool
    {
        return $this->transactionLink() !== null;
    }

    public function lastInsertId(): ?string
    {
        $link = $this->transactionLink() ?? $this->lastUsed();

        if ($link === null) {
            return null;
        }

        // The link may be idle again; borrow that exact link so no other task uses it meanwhile.
        $idleKey = array_search($link, $this->idle, true);

        if ($idleKey === false) {
            return $link->lastInsertId();
        }

        array_splice($this->idle, $idleKey, 1);
        $this->borrowed[spl_object_id($link)] = $link;

        try {
            return $link->lastInsertId();
        } finally {
            $this->discardOrRelease($link);
        }
    }

    public function execute(string $query, array $bindings = []): Result
    {
        $sql = $this->prepareSql($query);
        $pinned = $this->transactionLink();

        if ($pinned !== null) {
            return $pinned->query($sql, $bindings);
        }

        $link = $this->acquire();

        try {
            $result = $link->query($sql, $bindings);
        } catch (QueryException $e) {
            if (!$this->causedByLostConnection($e, $link)) {
                $this->release($link);
                throw $e;
            }

            $this->discard($link);
            $link = $this->acquire();

            try {
                $result = $link->query($sql, $bindings);
            } catch (\Throwable $retryError) {
                $this->discardOrRelease($link);
                throw $retryError;
            }
        } catch (\Throwable $e) {
            $this->discardOrRelease($link);
            throw $e;
        }

        $this->rememberLastUsed($link);
        $this->release($link);

        return $result;
    }

    public function reset(): void
    {
        $leaked = $this->mainTransaction;

        if ($leaked !== null && $leaked->rollbackBlocking()) {
            unset($this->borrowed[spl_object_id($leaked)]);
            $this->idle[] = $leaked;
        }

        foreach ($this->borrowed as $link) {
            // Still borrowed after the request ended: possibly mid-query, state unknown.
            $link->close();
        }

        $this->borrowed = [];
        $this->waiters = [];
        $this->mainTransaction = null;
        $this->fiberTransactions = new WeakMap();
        $this->forgetLastUsed();

        $idle = $this->idle;
        $this->idle = [];

        foreach ($idle as $link) {
            if (!$link->isHealthy() || !$link->isIdle() || $this->lifetimeExpired($link)) {
                $link->close();
                continue;
            }

            $this->idle[] = $link;
        }
    }

    protected function causedByLostConnection(QueryException $e, ConnectionLink $link): bool
    {
        return $e->isConnectionError() || !$link->isHealthy();
    }

    protected function now(): int
    {
        return time();
    }

    protected function configString(string $key): ?string
    {
        $value = $this->config[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * Returns the listed config values, failing when any of them is empty.
     *
     * @param list<string> $keys
     * @return array<string, string>
     */
    protected function requireConfig(array $keys): array
    {
        $values = [];

        foreach ($keys as $key) {
            $value = $this->configString($key) ?? '';

            if ($value === '') {
                throw new QueryException('Missing required database connection parameters');
            }

            $values[$key] = $value;
        }

        return $values;
    }

    private function finishTransaction(bool $commit): void
    {
        $link = $this->transactionLink();

        if ($link === null) {
            throw new QueryException('There is no active transaction', '25P01');
        }

        $this->unpin();

        try {
            $commit ? $link->commit() : $link->rollback();
        } catch (\Throwable $e) {
            $this->discardOrRelease($link);
            throw $e;
        }

        $this->release($link);
    }

    /**
     * Takes an idle link, opens a new one while below pool_size, or waits for one.
     */
    private function acquire(): ConnectionLink
    {
        if (Fiber::getCurrent() !== null && $this->mainTransaction !== null) {
            throw new LogicException(
                'Cannot run concurrent queries while a transaction is open: they would not see its uncommitted changes.',
            );
        }

        $link = array_pop($this->idle);

        if ($link === null && $this->openLinks() < $this->poolSize()) {
            $link = $this->open();
        }

        if ($link === null) {
            $suspension = $this->loop->getSuspension();
            $this->waiters[] = $suspension;

            /** @var ConnectionLink $link Handed over by release(). */
            $link = $suspension->suspend();

            return $link;
        }

        $this->borrowed[spl_object_id($link)] = $link;

        return $link;
    }

    private function open(): ConnectionLink
    {
        // Count the slot while connecting, so tasks connecting at once respect pool_size.
        $this->connecting++;

        try {
            return $this->openLink();
        } finally {
            $this->connecting--;
        }
    }

    /**
     * Returns the link to the pool, or hands it straight to the next waiter.
     */
    private function release(ConnectionLink $link): void
    {
        $waiter = array_shift($this->waiters);

        if ($waiter !== null) {
            $this->borrowed[spl_object_id($link)] = $link;
            $waiter->resume($link);
            return;
        }

        unset($this->borrowed[spl_object_id($link)]);
        $this->idle[] = $link;
    }

    private function discard(ConnectionLink $link): void
    {
        unset($this->borrowed[spl_object_id($link)]);
        $link->close();

        if ($this->mainLastUsed === $link) {
            $this->mainLastUsed = null;
        }
    }

    private function discardOrRelease(ConnectionLink $link): void
    {
        if ($link->isHealthy() && $link->isIdle()) {
            $this->release($link);
            return;
        }

        $this->discard($link);
    }

    private function transactionLink(): ?ConnectionLink
    {
        $fiber = Fiber::getCurrent();

        return $fiber === null ? $this->mainTransaction : ($this->fiberTransactions[$fiber] ?? null);
    }

    private function pin(ConnectionLink $link): void
    {
        $fiber = Fiber::getCurrent();

        if ($fiber === null) {
            $this->mainTransaction = $link;
        } else {
            $this->fiberTransactions[$fiber] = $link;
        }
    }

    private function unpin(): void
    {
        $fiber = Fiber::getCurrent();

        if ($fiber === null) {
            $this->mainTransaction = null;
        } else {
            unset($this->fiberTransactions[$fiber]);
        }
    }

    private function rememberLastUsed(ConnectionLink $link): void
    {
        $fiber = Fiber::getCurrent();

        if ($fiber === null) {
            $this->mainLastUsed = $link;
        } else {
            $this->fiberLastUsed[$fiber] = $link;
        }
    }

    private function lastUsed(): ?ConnectionLink
    {
        $fiber = Fiber::getCurrent();

        return $fiber === null ? $this->mainLastUsed : ($this->fiberLastUsed[$fiber] ?? null);
    }

    private function forgetLastUsed(): void
    {
        $this->mainLastUsed = null;
        $this->fiberLastUsed = new WeakMap();
    }

    private function openLinks(): int
    {
        return count($this->idle) + count($this->borrowed) + $this->connecting;
    }

    private function poolSize(): int
    {
        $size = $this->config['pool_size'] ?? self::DEFAULT_POOL_SIZE;

        return max(1, is_numeric($size) ? (int) $size : self::DEFAULT_POOL_SIZE);
    }

    private function lifetimeExpired(ConnectionLink $link): bool
    {
        $maxLifetime = $this->config['max_lifetime'] ?? 0;
        $maxLifetime = is_numeric($maxLifetime) ? (int) $maxLifetime : 0;

        return $maxLifetime > 0 && $this->now() - $link->connectedAt() >= $maxLifetime;
    }
}
