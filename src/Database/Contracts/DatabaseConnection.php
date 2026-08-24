<?php

declare(strict_types=1);

namespace Atria\Database\Contracts;

use PDOStatement;

interface DatabaseConnection
{
    public function connect(): void;

    public function disconnect(): void;

    public function getConnection(): mixed;

    public function beginTransaction(): void;

    public function isConnected(): bool;

    public function commit(): void;

    public function rollback(): void;

    /**
     * @param array<int,mixed> $bindings
     * @return PDOStatement|bool
     */
    public function execute(string $query, array $bindings): PDOStatement|bool;
}
