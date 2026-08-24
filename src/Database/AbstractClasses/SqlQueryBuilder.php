<?php

declare(strict_types=1);

namespace Atria\Database\AbstractClasses;

use PDO;
use PDOStatement;
use Atria\Database\Contracts\QueryBuilder;
use Atria\Database\Contracts\DatabaseConnection;

abstract class SqlQueryBuilder implements QueryBuilder
{
    protected DatabaseConnection $dbConnection;

    /** @var array<int, string> */
    protected array $select = [];
    protected string $from = '';
    protected string $insertTable = '';
    /** @var array<int, string> */
    protected array $insertColumns = [];
    /** @var array<int, array<int, mixed>> */
    protected array $insertValues = [];
    protected string $updateTable = '';
    /** @var array<int, string> */
    protected array $updateData = [];
    protected string $createTable = '';
    /** @var array<string, string> */
    protected array $createColumns = [];
    protected string $deleteFrom = '';
    protected string $dropTable = '';
    /** @var array<int, array<string, mixed>> */
    protected array $where = [];
    /** @var array<int, string> */
    protected array $returning = [];
    /** @var array<int, mixed> */
    protected array $bindings = [];
    /** @var array<int, string> */
    protected array $joins = [];
    /** @var array<int, string> */
    protected array $orderBy = [];
    protected ?int $limit = null;
    protected ?int $offset = null;

    /** @var array<int, string> Valid comparison operators accepted by where(). Override in a subclass to extend/restrict. */
    protected const VALID_OPERATORS = ['=', '!=', '<', '>', '<=', '>=', 'LIKE', 'IN', 'NOT IN'];

    public function __construct(DatabaseConnection $dbConnection)
    {
        $this->dbConnection = $dbConnection;
    }

    /**
     * Resets all internal query state so the same instance can be reused
     * to build a new query.
     */
    protected function reset(): void
    {
        $this->select = [];
        $this->from = '';
        $this->insertTable = '';
        $this->insertColumns = [];
        $this->insertValues = [];
        $this->updateTable = '';
        $this->updateData = [];
        $this->createTable = '';
        $this->createColumns = [];
        $this->deleteFrom = '';
        $this->dropTable = '';
        $this->where = [];
        $this->returning = [];
        $this->bindings = [];
        $this->joins = [];
        $this->orderBy = [];
        $this->limit = null;
        $this->offset = null;
    }

    /**
     * @param array<int, string> $columns
     */
    public function select(array $columns): self
    {
        $this->select = $columns;
        return $this;
    }

    public function from(string $table): self
    {
        $this->from = $table;
        return $this;
    }

    public function where(string $column, string $operator, mixed $value): self
    {
        if (!in_array($operator, static::VALID_OPERATORS, true)) {
            throw new \InvalidArgumentException("Invalid operator: {$operator}");
        }

        $this->where[] = [
            'type' => 'AND',
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
        ];

        if (($operator === 'IN' || $operator === 'NOT IN') && is_array($value)) {
            /** @var array<int, mixed> $value */
            $this->bindings = array_merge($this->bindings, $value);
        } else {
            $this->bindings[] = $value;
        }

        return $this;
    }

    public function whereNull(string $column): self
    {
        $this->where[] = [
            'type' => 'AND',
            'column' => $column,
            'operator' => 'IS NULL',
            'value' => null,
        ];

        return $this;
    }

    public function whereNotNull(string $column): self
    {
        $this->where[] = [
            'type' => 'AND',
            'column' => $column,
            'operator' => 'IS NOT NULL',
            'value' => null,
        ];

        return $this;
    }

    public function join(string $table, string $first, string $operator, string $second): self
    {
        $this->joins[] = "INNER JOIN {$table} ON {$first} {$operator} {$second}";
        return $this;
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        $this->joins[] = "LEFT JOIN {$table} ON {$first} {$operator} {$second}";
        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $this->orderBy[] = "{$column} {$direction}";
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }
    /**
     * @param array<int, string> $columns
     */
    public function insertInto(string $tableName, array $columns): self
    {
        $this->insertTable = $tableName;
        $this->insertColumns = $columns;
        return $this;
    }
    /**
     * @param array<int,mixed> $data
     */
    public function values(array $data): self
    {
        $this->insertValues[] = $data;
        foreach ($data as $value) {
            $this->bindings[] = $value;
        }
        return $this;
    }

    public function update(string $tableName): self
    {
        $this->updateTable = $tableName;
        return $this;
    }
    /**
     * @param array<string, mixed> $data
     */
    public function set(array $data): self
    {
        $set = [];
        foreach ($data as $column => $value) {
            $set[] = "{$column} = ?";
            $this->bindings[] = $value;
        }

        $this->updateData = $set;
        return $this;
    }

    /**
     * @param array<int, string> $columns
     */
    public function returning(array $columns = ['*']): self
    {
        $this->returning = $columns;

        return $this;
    }

    public function deleteFrom(string $tableName): self
    {
        $this->deleteFrom = $tableName;
        return $this;
    }
    /**
     * @param array<string, string> $columns
     */
    public function createTable(string $tableName, array $columns): self
    {
        $this->createTable = $tableName;
        $this->createColumns = $columns;
        return $this;
    }

    public function dropTable(string $tableName): self
    {
        $this->dropTable = $tableName;
        return $this;
    }

    // ---------------------------------------------------------------
    // Reusable clause builders — shared SQL syntax across dialects.
    // Subclasses compose these inside their own getQuery() implementation.
    // ---------------------------------------------------------------

    protected function buildSelectClause(): string
    {
        if (empty($this->select) || !$this->from) {
            return '';
        }

        $sql = 'SELECT ' . implode(', ', $this->select) . " FROM {$this->from}";

        if (!empty($this->joins)) {
            $sql .= ' ' . implode(' ', $this->joins);
        }

        return $sql;
    }

    protected function buildUpdateClause(): string
    {
        if (!$this->updateTable || empty($this->updateData)) {
            return '';
        }

        return "UPDATE {$this->updateTable} SET " . implode(', ', $this->updateData);
    }

    protected function buildDeleteClause(): string
    {
        if (!$this->deleteFrom) {
            return '';
        }

        return "DELETE FROM {$this->deleteFrom}";
    }

    protected function buildWhereClause(): string
    {
        if (empty($this->where)) {
            return '';
        }

        $conditions = [];
        foreach ($this->where as $index => $condition) {
            $condType = is_string($condition['type'] ?? null) ? $condition['type'] : 'AND';
            $condColumn = is_string($condition['column'] ?? null) ? $condition['column'] : '';
            $condOperator = is_string($condition['operator'] ?? null) ? $condition['operator'] : '=';
            $condValue = $condition['value'] ?? null;

            $prefix = $index === 0 ? '' : $condType . ' ';

            if ($condOperator === 'IS NULL' || $condOperator === 'IS NOT NULL') {
                $conditions[] = $prefix . "{$condColumn} {$condOperator}";
            } elseif (($condOperator === 'IN' || $condOperator === 'NOT IN') && is_array($condValue)) {
                $placeholders = implode(', ', array_fill(0, count($condValue), '?'));
                $conditions[] = $prefix . "{$condColumn} {$condOperator} ({$placeholders})";
            } else {
                $conditions[] = $prefix . "{$condColumn} {$condOperator} ?";
            }
        }

        return 'WHERE ' . implode(' ', $conditions);
    }

    protected function buildOrderByLimitOffsetClause(): string
    {
        $sql = '';

        if (!empty($this->orderBy)) {
            $sql .= 'ORDER BY ' . implode(', ', $this->orderBy);
        }

        if ($this->limit !== null) {
            $sql .= ($sql ? ' ' : '') . "LIMIT {$this->limit}";
        }

        if ($this->offset !== null) {
            $sql .= ($sql ? ' ' : '') . "OFFSET {$this->offset}";
        }

        return $sql;
    }

    protected function buildInsertClause(): string
    {
        if (!$this->insertTable || empty($this->insertColumns)) {
            return '';
        }

        $columns = implode(', ', $this->insertColumns);
        $sql = "INSERT INTO {$this->insertTable} ({$columns}) VALUES";

        $valueGroups = [];
        foreach ($this->insertValues as $values) {
            $placeholders = implode(', ', array_fill(0, count($values), '?'));
            $valueGroups[] = "({$placeholders})";
        }

        return $sql . ' ' . implode(', ', $valueGroups);
    }

    protected function buildCreateTableClause(): string
    {
        if (!$this->createTable || empty($this->createColumns)) {
            return '';
        }

        $cols = [];
        foreach ($this->createColumns as $name => $type) {
            $cols[] = "{$name} {$type}";
        }

        return "CREATE TABLE IF NOT EXISTS {$this->createTable} (" . implode(', ', $cols) . ')';
    }

    protected function buildDropTableClause(): string
    {
        if (!$this->dropTable) {
            return '';
        }

        return "DROP TABLE IF EXISTS {$this->dropTable}";
    }

    /**
     * Executes the built query and returns the resulting rows.
     * Shared across dialects since it only depends on the PDO connection.
     *
     * @return array<int, array<string, mixed>>
     */
    public function execute(): array
    {
        $stmt = $this->dbConnection->execute($this->getQuery(), $this->bindings);
        /** @var array<int, array<string, mixed>> $result */
        $result = ($stmt instanceof PDOStatement) ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $this->reset();
        return $result;
    }

    public function first(): ?array
    {
        if ($this->limit === null) {
            $this->limit(1);
        }

        $result = $this->execute();

        return $result[0] ?? null;
    }

    public function exists(): bool
    {
        if ($this->limit === null) {
            $this->limit(1);
        }

        return $this->first() !== null;
    }

    /**
     * @param array<int, mixed> $bindings
     * @return array<int, array<string, mixed>>
     */
    public function statement(string $sql, array $bindings = []): array
    {
        $this->reset();

        $stmt = $this->dbConnection->execute($sql, $bindings);
        /** @var array<int, array<string, mixed>> $result */
        $result = ($stmt instanceof PDOStatement) ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        $this->reset();

        return $result;
    }

    protected function buildReturningClause(bool $defaultInsertReturning = false): string
    {
        if ($this->returning !== []) {
            return ' RETURNING ' . implode(', ', $this->returning);
        }

        return $defaultInsertReturning ? ' RETURNING *' : '';
    }

    // ---------------------------------------------------------------
    // Dialect-specific — every concrete builder must implement these.
    // ---------------------------------------------------------------

    /**
     * Returns the raw SQL query string built so far.
     * Left abstract because final composition, identifier quoting,
     * and clauses like RETURNING differ between dialects.
     */
    abstract public function getQuery(): string;

    /**
     * Creates a regular (non-unique) index on one or more columns of a table.
     *
     * @param array<int, string> $columns
     */
    abstract public function createIndex(string $indexName, string $tableName, array $columns): self;

    /**
     * Creates a unique index on one or more columns of a table.
     *
     * @param array<int, string> $columns
     */
    abstract public function createUniqueIndex(string $indexName, string $tableName, array $columns): self;

    /**
     * Drops (removes) an existing index.
     * $tableName is required by dialects (e.g. MySQL) where the index
     * is scoped to the table, and can be omitted in others (e.g. PostgreSQL).
     */
    abstract public function dropIndex(string $indexName, ?string $tableName = null): self;
}
