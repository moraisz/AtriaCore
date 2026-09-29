<?php

declare(strict_types=1);

namespace Atria\Database\Connections;

use Atria\Async\EventLoop;
use Atria\Database\Contracts\ConnectionLink;
use Atria\Database\Exceptions\QueryException;
use Atria\Database\Result;
use PgSql\Connection;
use PgSql\Result as PgResult;

/**
 * One ext-pgsql connection driven without blocking the thread: connecting and
 * waiting for results suspend the current Async::run() task on the event loop.
 *
 * @internal Managed by PgSqlConnection.
 */
final class PgSqlLink implements ConnectionLink
{
    /** Type OIDs converted to PHP types: integers, floats and booleans. */
    private const INT_TYPES = [20 => true, 21 => true, 23 => true, 26 => true];
    private const FLOAT_TYPES = [700 => true, 701 => true];
    private const BOOL_TYPE = 16;

    private function __construct(
        private Connection $connection,
        private EventLoop $loop,
        private readonly int $connectedAt,
    ) {}

    public static function open(string $dsn, EventLoop $loop, int $now): self
    {
        $connection = @pg_connect($dsn, PGSQL_CONNECT_ASYNC | PGSQL_CONNECT_FORCE_NEW);

        if ($connection === false) {
            throw new QueryException('Could not start the PostgreSQL connection.', '08001');
        }

        $link = new self($connection, $loop, $now);
        $link->finishConnecting();

        return $link;
    }

    /**
     * @param array<int, mixed> $bindings
     */
    public function query(string $sql, array $bindings): Result
    {
        $params = array_map(self::toParameter(...), array_values($bindings));

        if (!@pg_send_query_params($this->connection, $sql, $params)) {
            throw $this->connectionError();
        }

        $this->flush();

        while (pg_connection_busy($this->connection)) {
            $this->waitFor(readable: true);

            if (!pg_consume_input($this->connection)) {
                throw $this->connectionError();
            }
        }

        $result = null;
        $error = null;

        // Drain every result so the connection is idle for the next query.
        while (($next = pg_get_result($this->connection)) !== false) {
            $error ??= $this->resultError($next);
            $result = $next;
        }

        if ($error !== null) {
            throw $error;
        }

        if ($result === null) {
            throw $this->connectionError();
        }

        return new Result($this->rows($result), pg_affected_rows($result));
    }

    public function begin(): void
    {
        $this->query('BEGIN', []);
    }

    public function commit(): void
    {
        $this->query('COMMIT', []);
    }

    public function rollback(): void
    {
        $this->query('ROLLBACK', []);
    }

    public function lastInsertId(): ?string
    {
        try {
            $value = $this->query('SELECT LASTVAL() AS id', [])->rows[0]['id'] ?? null;
        } catch (QueryException) {
            // 55000: lastval is not yet defined in this session.
            return null;
        }

        return is_int($value) || is_string($value) ? (string) $value : null;
    }

    public function isHealthy(): bool
    {
        return pg_connection_status($this->connection) === PGSQL_CONNECTION_OK
            && !pg_connection_busy($this->connection);
    }

    public function isIdle(): bool
    {
        return pg_transaction_status($this->connection) === PGSQL_TRANSACTION_IDLE;
    }

    public function rollbackBlocking(): bool
    {
        if (pg_connection_busy($this->connection)) {
            return false;
        }

        return @pg_query($this->connection, 'ROLLBACK') !== false && $this->isIdle();
    }

    public function close(): void
    {
        @pg_close($this->connection);
    }

    public function connectedAt(): int
    {
        return $this->connectedAt;
    }

    private function finishConnecting(): void
    {
        while (true) {
            $status = pg_connect_poll($this->connection);

            match ($status) {
                PGSQL_POLLING_OK => null,
                PGSQL_POLLING_READING => $this->waitFor(readable: true),
                PGSQL_POLLING_WRITING => $this->waitFor(readable: false),
                default => throw new QueryException(
                    'Could not connect to PostgreSQL: ' . trim(pg_last_error($this->connection)),
                    '08001',
                ),
            };

            if ($status === PGSQL_POLLING_OK) {
                return;
            }
        }
    }

    /**
     * Sends whatever libpq still buffers for a large query.
     */
    private function flush(): void
    {
        while (($flushed = pg_flush($this->connection)) === 0) {
            $this->waitFor(readable: false);
        }

        if ($flushed === false) {
            throw $this->connectionError();
        }
    }

    private function waitFor(bool $readable): void
    {
        $socket = pg_socket($this->connection);

        if ($socket === false) {
            throw $this->connectionError();
        }

        $suspension = $this->loop->getSuspension();
        $loop = $this->loop;
        $callback = static function (string $id) use ($loop, $suspension): void {
            $loop->cancel($id);
            $suspension->resume();
        };

        $watcher = $readable
            ? $this->loop->onReadable($socket, $callback)
            : $this->loop->onWritable($socket, $callback);

        try {
            $suspension->suspend();
        } finally {
            $this->loop->cancel($watcher);
        }
    }

    private function resultError(PgResult $result): ?QueryException
    {
        $status = pg_result_status($result);

        if ($status !== PGSQL_FATAL_ERROR && $status !== PGSQL_BAD_RESPONSE && $status !== PGSQL_NONFATAL_ERROR) {
            return null;
        }

        $sqlState = pg_result_error_field($result, PGSQL_DIAG_SQLSTATE);
        $message = pg_result_error($result);

        return new QueryException(
            trim(is_string($message) ? $message : 'PostgreSQL query failed'),
            is_string($sqlState) ? $sqlState : 'HY000',
        );
    }

    private function connectionError(): QueryException
    {
        $message = trim(pg_last_error($this->connection));

        // Class 08: connection exception, so PooledConnection retries once on a fresh link.
        return new QueryException($message !== '' ? $message : 'PostgreSQL connection failed', '08006');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(PgResult $result): array
    {
        $fields = pg_num_fields($result);

        if ($fields === 0) {
            return [];
        }

        /** @var array<string, int> $types column => type OID, only for converted columns */
        $types = [];
        for ($i = 0; $i < $fields; $i++) {
            $oid = pg_field_type_oid($result, $i);

            if (is_int($oid) && (isset(self::INT_TYPES[$oid]) || isset(self::FLOAT_TYPES[$oid]) || $oid === self::BOOL_TYPE)) {
                $types[pg_field_name($result, $i)] = $oid;
            }
        }

        /** @var list<array<string, string|null>> $rows */
        $rows = pg_fetch_all($result, PGSQL_ASSOC);

        if ($types === []) {
            return $rows;
        }

        foreach ($rows as &$row) {
            foreach ($types as $column => $oid) {
                $value = $row[$column];

                if ($value === null) {
                    continue;
                }

                $row[$column] = match (true) {
                    isset(self::INT_TYPES[$oid]) => (int) $value,
                    isset(self::FLOAT_TYPES[$oid]) => (float) $value,
                    default => $value === 't',
                };
            }
        }
        unset($row);

        return $rows;
    }

    private static function toParameter(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? 't' : 'f',
            is_scalar($value), $value instanceof \Stringable => (string) $value,
            default => throw new \InvalidArgumentException('Unsupported binding type: ' . get_debug_type($value)),
        };
    }
}
