<?php

declare(strict_types=1);

use Atria\Database\Contracts\DatabaseConnection;
use Atria\Database\Result;

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

    public bool $transactionOpen = false;

    public ?string $nextInsertId = null;

    /** @var array<int, int> */
    public array $affectedRows = [];

    public function beginTransaction(): void
    {
        $this->transactionOpen = true;
    }

    public function inTransaction(): bool
    {
        return $this->transactionOpen;
    }

    public function lastInsertId(): ?string
    {
        return $this->nextInsertId;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function commit(): void
    {
        $this->transactionOpen = false;
    }

    public function rollback(): void
    {
        $this->transactionOpen = false;
    }

    public function execute(string $query, array $bindings = []): Result
    {
        $this->executedQueries[] = $query;
        $this->executedBindings[] = $bindings;

        $rows = array_values($this->returnRows);

        return new Result($rows, array_shift($this->affectedRows) ?? count($rows));
    }

    private function __clone() {}

    public function __sleep(): array
    {
        return [];
    }
    public function __wakeup(): void {}
}
