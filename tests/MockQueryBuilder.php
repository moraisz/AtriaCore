<?php

declare(strict_types=1);

use Atria\Database\Contracts\QueryBuilder;

/**
 * Collects every builder method call into a log array so tests can assert
 * what SQL clauses were assembled and what data was passed.
 *
 * execute() returns whatever $returnRows was set to (default []),
 * getQuery() returns the last collected operation label for quick smoke checks.
 */
class MockQueryBuilder implements QueryBuilder
{
    /** @var array<int, array<string, mixed>> */
    public array $log = [];

    /** @var array<int, array<string, mixed>> */
    private array $returnRows = [];

    /** @var array<int, array<int, array<string, mixed>>> */
    private array $returnSequence = [];

    private int $executeCount = 0;

    /** @param array<int, array<string, mixed>> $rows */
    public function setReturnRows(array $rows): void
    {
        $this->returnRows = $rows;
    }

    /**
     * @param array<int, array<int, array<string, mixed>>> $sequence
     */
    public function setReturnSequence(array $sequence): void
    {
        $this->returnSequence = $sequence;
    }

    public function select(array $columns): self
    {
        $this->log[] = ['method' => 'select', 'columns' => $columns];
        return $this;
    }
    public function from(string $table): self
    {
        $this->log[] = ['method' => 'from', 'table' => $table];
        return $this;
    }
    public function where(string $column, string $operator, mixed $value): self
    {
        $this->log[] = ['method' => 'where', 'column' => $column, 'operator' => $operator, 'value' => $value];
        return $this;
    }
    public function join(string $table, string $first, string $operator, string $second): self
    {
        $this->log[] = ['method' => 'join', 'table' => $table];
        return $this;
    }
    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        $this->log[] = ['method' => 'leftJoin', 'table' => $table];
        return $this;
    }
    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $this->log[] = ['method' => 'orderBy', 'column' => $column, 'direction' => $direction];
        return $this;
    }
    public function limit(int $limit): self
    {
        $this->log[] = ['method' => 'limit', 'limit' => $limit];
        return $this;
    }
    public function offset(int $offset): self
    {
        $this->log[] = ['method' => 'offset', 'offset' => $offset];
        return $this;
    }
    public function insertInto(string $tableName, array $columns): self
    {
        $this->log[] = ['method' => 'insertInto', 'table' => $tableName, 'columns' => $columns];
        return $this;
    }
    public function values(array $data): self
    {
        $this->log[] = ['method' => 'values', 'data' => $data];
        return $this;
    }
    public function update(string $tableName): self
    {
        $this->log[] = ['method' => 'update', 'table' => $tableName];
        return $this;
    }
    public function set(array $data): self
    {
        $this->log[] = ['method' => 'set', 'data' => $data];
        return $this;
    }
    public function whereNull(string $column): self
    {
        $this->log[] = ['method' => 'whereNull', 'column' => $column];
        return $this;
    }
    public function whereNotNull(string $column): self
    {
        $this->log[] = ['method' => 'whereNotNull', 'column' => $column];
        return $this;
    }
    public function returning(array $columns = ['*']): self
    {
        $this->log[] = ['method' => 'returning', 'columns' => $columns];
        return $this;
    }
    public function deleteFrom(string $tableName): self
    {
        $this->log[] = ['method' => 'deleteFrom', 'table' => $tableName];
        return $this;
    }
    public function createTable(string $tableName, array $columns): self
    {
        $this->log[] = ['method' => 'createTable', 'table' => $tableName, 'columns' => $columns];
        return $this;
    }
    public function dropTable(string $tableName): self
    {
        $this->log[] = ['method' => 'dropTable', 'table' => $tableName];
        return $this;
    }
    public function createIndex(string $indexName, string $tableName, array $columns): self
    {
        $this->log[] = ['method' => 'createIndex'];
        return $this;
    }
    public function createUniqueIndex(string $indexName, string $tableName, array $columns): self
    {
        $this->log[] = ['method' => 'createUniqueIndex'];
        return $this;
    }
    public function dropIndex(string $indexName, ?string $tableName = null): self
    {
        $this->log[] = ['method' => 'dropIndex'];
        return $this;
    }
    public function getQuery(): string
    {
        return $this->log[count($this->log) - 1]['method'] ?? 'unknown';
    }
    public function first(): ?array
    {
        return $this->returnRows[0] ?? null;
    }
    public function exists(): bool
    {
        return $this->returnRows !== [];
    }
    public function statement(string $sql, array $bindings = []): array
    {
        $this->log[] = ['method' => 'statement', 'sql' => $sql, 'bindings' => $bindings];
        return $this->returnRows;
    }
    public function execute(): array
    {
        if (!empty($this->returnSequence)) {
            $current = $this->returnSequence[$this->executeCount] ?? [];
            $this->executeCount++;
            return $current;
        }
        return $this->returnRows;
    }
}
