<?php

declare(strict_types=1);

namespace Atria\Database\AbstractClasses;

use Atria\Database\Contracts\DatabaseConnection;
use Atria\System\Contracts\Resettable;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/**
 * Base PDO connection kept alive across requests in worker mode.
 *
 * After every request reset() rolls back leaked transactions and recycles the
 * connection once `max_lifetime` expires. Statements executed outside a
 * transaction are retried once when the server dropped the connection.
 */
abstract class PdoConnection implements DatabaseConnection, Resettable
{
    /** @var array<int, string> Error fragments that mean the server connection is gone. */
    protected const LOST_CONNECTION_MESSAGES = [
        'server closed the connection',
        'no connection to the server',
        'connection timed out',
        'connection refused',
        'broken pipe',
        'ssl connection has been closed',
        'terminating connection',
        'gone away',
        'lost connection',
        'is dead or not enabled',
    ];

    protected ?PDO $connection = null;
    protected ?int $connectedAt = null;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(protected array $config = []) {}

    abstract protected function dsn(): string;

    /**
     * @return array<int, mixed>
     */
    protected function options(): array
    {
        return [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
    }

    /**
     * Runs driver-specific session setup right after the connection opens.
     */
    protected function configure(PDO $pdo): void {}

    protected function createPdo(): PDO
    {
        return new PDO(
            $this->dsn(),
            $this->configString('username'),
            $this->configString('password'),
            $this->options(),
        );
    }

    public function connect(): void
    {
        if ($this->connection !== null) {
            return;
        }

        $pdo = $this->createPdo();
        $this->configure($pdo);

        $this->connection = $pdo;
        $this->connectedAt = $this->now();
    }

    public function disconnect(): void
    {
        $this->connection = null;
        $this->connectedAt = null;
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
        $this->pdo()->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo()->commit();
    }

    public function rollback(): void
    {
        $this->pdo()->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->connection?->inTransaction() ?? false;
    }

    public function lastInsertId(): ?string
    {
        $id = $this->connection?->lastInsertId();

        return is_string($id) && $id !== '' && $id !== '0' ? $id : null;
    }

    public function execute(string $query, array $bindings = []): PDOStatement|bool
    {
        try {
            return $this->run($query, $bindings);
        } catch (PDOException $e) {
            if ($this->inTransaction() || !$this->causedByLostConnection($e)) {
                throw $e;
            }

            $this->disconnect();

            return $this->run($query, $bindings);
        }
    }

    public function reset(): void
    {
        if ($this->connection === null) {
            return;
        }

        try {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
        } catch (Throwable) {
            $this->disconnect();
            return;
        }

        if ($this->lifetimeExpired()) {
            $this->disconnect();
        }
    }

    /**
     * @param array<int, mixed> $bindings
     */
    protected function run(string $query, array $bindings): PDOStatement
    {
        $stmt = $this->pdo()->prepare($query);
        if (!$stmt instanceof PDOStatement) {
            throw new PDOException('Failed to prepare statement');
        }
        foreach (array_values($bindings) as $index => $value) {
            $stmt->bindValue($index + 1, $value, $this->parameterType($value));
        }
        $stmt->execute();
        return $stmt;
    }

    /**
     * Binds values by PHP type; PDOStatement::execute() would send false as ''.
     */
    protected function parameterType(mixed $value): int
    {
        return match (true) {
            $value === null => PDO::PARAM_NULL,
            is_bool($value) => PDO::PARAM_BOOL,
            is_int($value) => PDO::PARAM_INT,
            default => PDO::PARAM_STR,
        };
    }

    protected function causedByLostConnection(PDOException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? $e->getCode();
        if (is_string($sqlState) && str_starts_with($sqlState, '08')) {
            return true;
        }

        $message = strtolower($e->getMessage());
        foreach (static::LOST_CONNECTION_MESSAGES as $fragment) {
            if (str_contains($message, $fragment)) {
                return true;
            }
        }

        return false;
    }

    protected function lifetimeExpired(): bool
    {
        $maxLifetime = $this->config['max_lifetime'] ?? 0;
        $maxLifetime = is_numeric($maxLifetime) ? (int) $maxLifetime : 0;

        return $maxLifetime > 0
            && $this->connectedAt !== null
            && $this->now() - $this->connectedAt >= $maxLifetime;
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

    private function pdo(): PDO
    {
        $this->connect();

        if ($this->connection === null) {
            throw new PDOException('Database connection is not available');
        }

        return $this->connection;
    }
}
