<?php

declare(strict_types=1);

namespace Atria\Database\Connections;

use Atria\Async\EventLoop;
use Atria\Database\Contracts\ConnectionLink;
use Atria\Database\Exceptions\QueryException;
use Atria\Database\Result;
use mysqli;
use mysqli_result;
use mysqli_sql_exception;
use mysqli_stmt;

/**
 * One mysqli connection.
 *
 * Outside Async::run() tasks it runs native prepared statements. Inside a task it
 * sends the query with MYSQLI_ASYNC (values escaped into the SQL, since async
 * mysqli has no prepared statements) and suspends until MySqlPoller sees the
 * result, so tasks on other connections keep running. Connecting blocks:
 * mysqli has no asynchronous connect.
 *
 * @internal Managed by MySqlConnection.
 */
final class MySqlLink implements ConnectionLink
{
    /** Client errors that mean the server connection is gone. */
    private const LOST_CONNECTION_ERRNOS = [2006 => true, 2013 => true, 4031 => true];

    private bool $inTransaction = false;
    private bool $broken = false;
    private bool $busy = false;

    private function __construct(
        private readonly mysqli $connection,
        private readonly EventLoop $loop,
        private readonly MySqlPoller $poller,
        private readonly int $connectedAt,
    ) {}

    /**
     * @param array{host: string, port: string, database: string, username: string, password: string, charset: string} $config
     */
    public static function open(array $config, EventLoop $loop, MySqlPoller $poller, int $now): self
    {
        $connection = mysqli_init();

        if ($connection === false) {
            throw new QueryException('Could not initialise the MySQL connection.', '08001');
        }

        try {
            // Integers and floats come back as PHP numbers on both query paths.
            $connection->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, 1);

            // FOUND_ROWS: affected rows count matched rows, like the other drivers.
            $connected = $connection->real_connect(
                $config['host'],
                $config['username'],
                $config['password'],
                $config['database'],
                (int) $config['port'],
                null,
                MYSQLI_CLIENT_FOUND_ROWS,
            );

            if (!$connected || !$connection->set_charset($config['charset'])) {
                throw new QueryException('Could not connect to MySQL: ' . $connection->connect_error, '08001');
            }
        } catch (mysqli_sql_exception $e) {
            throw new QueryException('Could not connect to MySQL: ' . $e->getMessage(), '08001', $e);
        }

        return new self($connection, $loop, $poller, $now);
    }

    public function query(string $sql, array $bindings): Result
    {
        if ($this->broken) {
            throw new QueryException('The MySQL connection was lost.', '08S01');
        }

        try {
            return $this->loop->inAsyncTask()
                ? $this->queryAsync($sql, $bindings)
                : $this->queryPrepared($sql, $bindings);
        } catch (mysqli_sql_exception $e) {
            throw $this->toQueryException($e->getMessage(), $e->getCode(), $e->getSqlState(), $e);
        }
    }

    public function begin(): void
    {
        $this->transactionStatement('START TRANSACTION', fn(): bool => $this->connection->begin_transaction());
        $this->inTransaction = true;
    }

    public function commit(): void
    {
        $this->inTransaction = false;
        $this->transactionStatement('COMMIT', fn(): bool => $this->connection->commit());
    }

    public function rollback(): void
    {
        $this->inTransaction = false;
        $this->transactionStatement('ROLLBACK', fn(): bool => $this->connection->rollback());
    }

    public function lastInsertId(): ?string
    {
        $id = $this->connection->insert_id;

        return $id === 0 || $id === '0' || $id === '' ? null : (string) $id;
    }

    public function isHealthy(): bool
    {
        return !$this->broken && !$this->busy;
    }

    public function isIdle(): bool
    {
        return !$this->inTransaction;
    }

    public function rollbackBlocking(): bool
    {
        if (!$this->isHealthy()) {
            return false;
        }

        $this->inTransaction = false;

        try {
            return $this->connection->query('ROLLBACK') !== false;
        } catch (mysqli_sql_exception) {
            $this->broken = true;

            return false;
        }
    }

    public function close(): void
    {
        try {
            $this->connection->close();
        } catch (\Throwable) {
            // Already closed or lost.
        }
    }

    public function connectedAt(): int
    {
        return $this->connectedAt;
    }

    /**
     * MySQL rejects START TRANSACTION in the prepared statement protocol, so
     * outside tasks the mysqli transaction API runs it; inside tasks the
     * asynchronous text query does.
     *
     * @param \Closure(): bool $blocking
     */
    private function transactionStatement(string $sql, \Closure $blocking): void
    {
        if ($this->loop->inAsyncTask()) {
            $this->query($sql, []);
            return;
        }

        try {
            if (!$blocking()) {
                throw $this->lastError();
            }
        } catch (mysqli_sql_exception $e) {
            throw $this->toQueryException($e->getMessage(), $e->getCode(), $e->getSqlState(), $e);
        }
    }

    /**
     * @param array<int, mixed> $bindings
     */
    private function queryPrepared(string $sql, array $bindings): Result
    {
        $statement = $this->connection->prepare($sql);

        if (!$statement instanceof mysqli_stmt) {
            throw $this->lastError();
        }

        try {
            $values = array_map(self::toParameter(...), array_values($bindings));

            if ($values !== []) {
                $types = implode('', array_map(self::parameterType(...), $values));
                $statement->bind_param($types, ...$values);
            }

            if (!$statement->execute()) {
                throw $this->statementError($statement);
            }

            return new Result(
                $this->rows($statement->get_result()),
                max(0, (int) $statement->affected_rows),
            );
        } finally {
            $statement->close();
        }
    }

    /**
     * @param array<int, mixed> $bindings
     */
    private function queryAsync(string $sql, array $bindings): Result
    {
        $sql = MySqlPlaceholders::interpolate($sql, $bindings, $this->connection->real_escape_string(...));

        if ($this->connection->query($sql, MYSQLI_ASYNC) === false) {
            throw $this->lastError();
        }

        $this->busy = true;
        $this->poller->wait($this->connection);

        $result = $this->connection->reap_async_query();
        $this->busy = false;

        if ($result === false) {
            throw $this->lastError();
        }

        return new Result(
            $this->rows($result),
            max(0, (int) $this->connection->affected_rows),
        );
    }

    /**
     * Statements without a result set (INSERT, DDL) return true or false instead of a result.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(mixed $result): array
    {
        if (!$result instanceof mysqli_result) {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();

        return $rows;
    }

    private function lastError(): QueryException
    {
        return $this->toQueryException($this->connection->error, $this->connection->errno, $this->connection->sqlstate);
    }

    private function statementError(mysqli_stmt $statement): QueryException
    {
        return $this->toQueryException($statement->error, $statement->errno, $statement->sqlstate);
    }

    private function toQueryException(string $message, int $errno, string $sqlState, ?\Throwable $previous = null): QueryException
    {
        $this->busy = false;

        if (isset(self::LOST_CONNECTION_ERRNOS[$errno])) {
            $this->broken = true;
            $sqlState = '08S01';
        }

        return new QueryException($message !== '' ? $message : 'MySQL query failed', $sqlState !== '' ? $sqlState : 'HY000', $previous);
    }

    private static function toParameter(mixed $value): int|float|string|null
    {
        return match (true) {
            $value === null, is_int($value), is_float($value), is_string($value) => $value,
            is_bool($value) => (int) $value,
            $value instanceof \Stringable => (string) $value,
            default => throw new \InvalidArgumentException('Unsupported binding type: ' . get_debug_type($value)),
        };
    }

    private static function parameterType(int|float|string|null $value): string
    {
        return match (true) {
            is_int($value) => 'i',
            is_float($value) => 'd',
            default => 's',
        };
    }
}
