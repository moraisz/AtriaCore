<?php

declare(strict_types=1);

namespace Atria\Database\Connections;

use Atria\Database\Contracts\DatabaseConnection;
use PDO;
use PDOException;
use PDOStatement;

class PgSqlConnection implements DatabaseConnection
{
    private ?PDO $connection = null;
    private bool $isConnected = false;
    /** @var array<string, string> */
    private array $config;

    /**
     * @param array<string, string> $config
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function connect(): void
    {
        if ($this->isConnected) {
            return;
        }

        $host = $this->config['host'];
        $port = $this->config['port'];
        $database = $this->config['database'];
        $username = $this->config['username'];
        $password = $this->config['password'];

        if ($host === '' || $port === '' || $database === '' || $username === '' || $password === '') {
            throw new PDOException('Missing required database connection parameters');
        }

        try {
            $dsn = sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                $host,
                $port,
                $database,
            );

            $this->connection = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            $this->isConnected = true;
        } catch (PDOException $e) {
            throw $e;
        }
    }

    public function disconnect(): void
    {
        if (!$this->isConnected) {
            return;
        }

        $this->connection = null;
        $this->isConnected = false;
    }

    public function getConnection(): mixed
    {
        return $this->connection;
    }

    public function isConnected(): bool
    {
        return $this->isConnected;
    }

    public function beginTransaction(): void
    {
        $this->connect();
        $this->connection?->beginTransaction();
    }

    public function commit(): void
    {
        $this->connect();
        $this->connection?->commit();
    }

    public function rollback(): void
    {
        $this->connect();
        $this->connection?->rollBack();
    }

    public function execute(string $query, array $bindings = []): PDOStatement|bool
    {
        $this->connect();
        $stmt = $this->connection?->prepare($query);
        if (!$stmt instanceof PDOStatement) {
            throw new PDOException('Failed to prepare statement');
        }
        $stmt->execute($bindings);
        return $stmt;
    }
}
