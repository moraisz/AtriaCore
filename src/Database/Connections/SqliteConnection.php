<?php

declare(strict_types=1);

namespace Atria\Database\Connections;

use Atria\Database\Contracts\DatabaseConnection;
use Atria\Database\Exceptions\QueryException;
use Atria\Database\Result;
use Atria\System\Contracts\Resettable;
use RuntimeException;
use SQLite3;
use SQLite3Result;
use SQLite3Stmt;

/**
 * SQLite through ext-sqlite3, one connection per worker.
 *
 * `database` is a file path or `:memory:`; foreign keys are enforced. SQLite
 * works on a local file and has no asynchronous API, so queries inside
 * Async::concurrently() run one after another. Between requests, reset() rolls back
 * a leaked transaction and recycles the connection once `max_lifetime`
 * (seconds, 0 = never) expires.
 */
class SqliteConnection implements DatabaseConnection, Resettable
{
    protected ?SQLite3 $connection = null;
    protected ?int $connectedAt = null;

    /** Tracked here: ext-sqlite3 cannot report whether a transaction is open. */
    private bool $inTransaction = false;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(protected array $config = [])
    {
        if (!extension_loaded('sqlite3')) {
            throw new RuntimeException(static::class . ' requires the sqlite3 PHP extension.');
        }
    }

    public function connect(): void
    {
        if ($this->connection !== null) {
            return;
        }

        $database = $this->config['database'] ?? null;
        $database = is_scalar($database) ? (string) $database : '';

        if ($database === '') {
            throw new QueryException('Missing required database connection parameters');
        }

        try {
            $connection = new SQLite3($database);
        } catch (\Exception $e) {
            throw new QueryException('Could not open the SQLite database: ' . $e->getMessage(), '08001', $e);
        }

        $connection->enableExceptions(true);
        $connection->exec('PRAGMA foreign_keys = ON');

        $this->connection = $connection;
        $this->connectedAt = $this->now();
    }

    public function disconnect(): void
    {
        $this->connection?->close();
        $this->connection = null;
        $this->connectedAt = null;
        $this->inTransaction = false;
    }

    public function getConnection(): mixed
    {
        return $this->connection;
    }

    public function isConnected(): bool
    {
        return $this->connection !== null;
    }

    public function beginTransaction(): void
    {
        if ($this->inTransaction()) {
            throw new QueryException('There is already an active transaction', '25001');
        }

        $this->exec('BEGIN');
        $this->inTransaction = true;
    }

    public function commit(): void
    {
        $this->requireTransaction();
        $this->inTransaction = false;
        $this->exec('COMMIT');
    }

    public function rollback(): void
    {
        $this->requireTransaction();
        $this->inTransaction = false;
        $this->exec('ROLLBACK');
    }

    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }

    public function lastInsertId(): ?string
    {
        $id = $this->connection?->lastInsertRowID() ?? 0;

        return $id > 0 ? (string) $id : null;
    }

    public function execute(string $query, array $bindings = []): Result
    {
        $connection = $this->sqlite();

        try {
            $statement = $connection->prepare($query);

            if (!$statement instanceof SQLite3Stmt) {
                throw new QueryException($connection->lastErrorMsg());
            }

            foreach (array_values($bindings) as $index => $value) {
                [$value, $type] = self::parameter($value);
                $statement->bindValue($index + 1, $value, $type);
            }

            $result = $this->run($connection, $statement);

            // fetchArray() on a statement without columns would run it again.
            $hasColumns = $result->numColumns() > 0;
            $rows = $hasColumns ? $this->rows($result) : [];
            $result->finalize();
            $statement->close();

            // changes() keeps the count of the last write, so it only applies to writes.
            return new Result($rows, $hasColumns ? count($rows) : $connection->changes());
        } catch (QueryException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new QueryException($e->getMessage(), 'HY000', $e);
        }
    }

    public function reset(): void
    {
        if ($this->connection === null) {
            return;
        }

        try {
            if ($this->inTransaction) {
                $this->inTransaction = false;
                $this->connection->exec('ROLLBACK');
            }
        } catch (\Throwable) {
            $this->disconnect();
            return;
        }

        if ($this->lifetimeExpired()) {
            $this->disconnect();
        }
    }

    protected function now(): int
    {
        return time();
    }

    /**
     * ext-sqlite3 runs a statement once in execute() and again on the first
     * fetchArray(). That is harmless for reads, but a write with RETURNING
     * would be applied twice: run such writes inside a savepoint and undo the
     * first run, so the fetch applies them exactly once.
     */
    private function run(SQLite3 $connection, SQLite3Stmt $statement): SQLite3Result
    {
        $write = !$statement->readOnly();

        if ($write) {
            $connection->exec('SAVEPOINT atria_write');
        }

        try {
            $result = $statement->execute();
        } catch (\Exception $e) {
            if ($write) {
                $connection->exec('ROLLBACK TO atria_write');
                $connection->exec('RELEASE atria_write');
            }

            throw $e;
        }

        if ($write) {
            if ($result instanceof SQLite3Result && $result->numColumns() > 0) {
                $connection->exec('ROLLBACK TO atria_write');
            }

            $connection->exec('RELEASE atria_write');
        }

        if (!$result instanceof SQLite3Result) {
            throw new QueryException($connection->lastErrorMsg());
        }

        return $result;
    }

    private function exec(string $sql): void
    {
        try {
            $this->sqlite()->exec($sql);
        } catch (\Exception $e) {
            throw new QueryException($e->getMessage(), 'HY000', $e);
        }
    }

    private function sqlite(): SQLite3
    {
        $this->connect();

        if ($this->connection === null) {
            throw new QueryException('The SQLite connection is not available.', '08003');
        }

        return $this->connection;
    }

    private function requireTransaction(): void
    {
        if (!$this->inTransaction()) {
            throw new QueryException('There is no active transaction', '25P01');
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(SQLite3Result $result): array
    {
        $rows = [];

        while (($row = $result->fetchArray(SQLITE3_ASSOC)) !== false) {
            /** @var array<string, mixed> $row */
            $rows[] = $row;
        }

        return $rows;
    }

    private function lifetimeExpired(): bool
    {
        $maxLifetime = $this->config['max_lifetime'] ?? 0;
        $maxLifetime = is_numeric($maxLifetime) ? (int) $maxLifetime : 0;

        return $maxLifetime > 0
            && $this->connectedAt !== null
            && $this->now() - $this->connectedAt >= $maxLifetime;
    }

    /**
     * @return array{0: int|float|string|null, 1: int}
     */
    private static function parameter(mixed $value): array
    {
        return match (true) {
            $value === null => [null, SQLITE3_NULL],
            is_bool($value) => [(int) $value, SQLITE3_INTEGER],
            is_int($value) => [$value, SQLITE3_INTEGER],
            is_float($value) => [$value, SQLITE3_FLOAT],
            is_string($value), $value instanceof \Stringable => [(string) $value, SQLITE3_TEXT],
            default => throw new \InvalidArgumentException('Unsupported binding type: ' . get_debug_type($value)),
        };
    }
}
