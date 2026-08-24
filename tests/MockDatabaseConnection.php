<?php

declare(strict_types=1);

use Atria\Database\Contracts\DatabaseConnection;

class MockDatabaseConnection implements DatabaseConnection
{
    /** @var array<int, array<string, mixed>> */
    private array $returnRows = [];

    /** @var array<int, string> */
    public array $executedQueries = [];

    /** @var array<int, array<int, mixed>> */
    public array $executedBindings = [];

    private bool $connected = false;

    /** @param array<int, array<string, mixed>> $rows */
    public function setReturnRows(array $rows): void
    {
        $this->returnRows = $rows;
    }

    public function connect(): void
    {
        $this->connected = true;
    }

    public function disconnect(): void
    {
        $this->connected = false;
    }

    public function getConnection(): mixed
    {
        return null;
    }

    public function beginTransaction(): void {}

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function commit(): void {}

    public function rollback(): void {}

    public function execute(string $query, array $bindings = []): PDOStatement|bool
    {
        $this->executedQueries[] = $query;
        $this->executedBindings[] = $bindings;

        $rows = [];
        foreach ($this->returnRows as $row) {
            $rows[] = $row;
        }

        return new MockPDOStatement($rows);
    }

    private function __clone() {}

    public function __sleep(): array
    {
        return [];
    }
    public function __wakeup(): void {}
}

class MockPDOStatement extends PDOStatement
{
    /** @var array<int, array<string, mixed>> */
    private array $rows;

    /** @param array<int, array<string, mixed>> $rows */
    public function __construct(array $rows = [])
    {
        $this->rows = $rows;
    }

    /** @return array<int, array<string, mixed>> */
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->rows;
    }
}
