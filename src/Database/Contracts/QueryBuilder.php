<?php

declare(strict_types=1);

namespace Atria\Database\Contracts;

interface QueryBuilder
{
    /**
     * Specifies the columns to be selected in a SELECT query.
     *
     * @param array<int, string> $columns Column names to select.
     */
    public function select(array $columns): self;

    /**
     * Specifies the table to select, update, or delete from.
     *
     * @param string $table Table name.
     */
    public function from(string $table): self;

    /**
     * Adds a WHERE condition to the query.
     *
     * @param string $column Column name to filter by.
     * @param string $operator Comparison operator (e.g. '=', '>', 'LIKE').
     * @param mixed $value Value to compare the column against.
     */
    public function where(string $column, string $operator, mixed $value): self;

    /**
     * Adds an INNER JOIN clause to the query.
     *
     * @param string $table Table to join.
     * @param string $first Column from the first table.
     * @param string $operator Comparison operator used to join both columns.
     * @param string $second Column from the joined table.
     */
    public function join(string $table, string $first, string $operator, string $second): self;

    /**
     * Adds a LEFT JOIN clause to the query.
     *
     * @param string $table Table to join.
     * @param string $first Column from the first table.
     * @param string $operator Comparison operator used to join both columns.
     * @param string $second Column from the joined table.
     */
    public function leftJoin(string $table, string $first, string $operator, string $second): self;

    /**
     * Adds an ORDER BY clause to the query.
     *
     * @param string $column Column name to order by.
     * @param string $direction Sort direction, either 'ASC' or 'DESC'.
     */
    public function orderBy(string $column, string $direction = 'ASC'): self;

    /**
     * Limits the number of rows returned by the query.
     *
     * @param int $limit Maximum number of rows to return.
     */
    public function limit(int $limit): self;

    /**
     * Skips the given number of rows before returning results.
     *
     * @param int $offset Number of rows to skip.
     */
    public function offset(int $offset): self;

    /**
     * Starts an INSERT INTO query for the given table and columns.
     *
     * @param string $tableName Table name to insert into.
     * @param array<int, string> $columns Columns that will receive values.
     */
    public function insertInto(string $tableName, array $columns): self;

    /**
     * Sets the values to be inserted, matching the order of columns
     * defined in insertInto().
     *
     * @param array<int, mixed> $data Values to insert.
     */
    public function values(array $data): self;

    /**
     * Starts an UPDATE query for the given table.
     *
     * @param string $tableName Table name to update.
     */
    public function update(string $tableName): self;

    /**
     * Defines the column-value pairs to be updated.
     *
     * @param array<string, mixed> $data Associative array of column => value.
     */
    public function set(array $data): self;

    /**
     * Adds a `column IS NULL` condition to the query.
     */
    public function whereNull(string $column): self;

    /**
     * Adds a `column IS NOT NULL` condition to the query.
     */
    public function whereNotNull(string $column): self;

    /**
     * Adds a RETURNING clause for dialects that support it.
     *
     * @param array<int, string> $columns
     */
    public function returning(array $columns = ['*']): self;

    /**
     * Starts a DELETE query for the given table.
     *
     * @param string $tableName Table name to delete from.
     */
    public function deleteFrom(string $tableName): self;

    /**
     * Creates a new table with the given columns and their types/definitions.
     *
     * @param string $tableName Table name to create.
     * @param array<string, string> $columns Associative array of column name => column definition.
     */
    public function createTable(string $tableName, array $columns): self;

    /**
     * Drops (removes) an existing table.
     *
     * @param string $tableName Table name to drop.
     */
    public function dropTable(string $tableName): self;

    /**
     * Creates a regular (non-unique) index on one or more columns of a table.
     *
     * @param string $indexName Name of the index to be created.
     * @param string $tableName Name of the table where the index will be created.
     * @param array<int, string> $columns Columns to be included in the index.
     */
    public function createIndex(string $indexName, string $tableName, array $columns): self;

    /**
     * Creates a unique index on one or more columns of a table.
     *
     * @param string $indexName Name of the index to be created.
     * @param string $tableName Name of the table where the index will be created.
     * @param array<int, string> $columns Columns to be included in the index.
     */
    public function createUniqueIndex(string $indexName, string $tableName, array $columns): self;

    /**
     * Drops (removes) an existing index.
     *
     * @param string $indexName Name of the index to be removed.
     * @param string|null $tableName Required by some DBMSs (e.g. MySQL), where the index
     *                               is scoped to the table. Can be omitted in others (e.g. PostgreSQL).
     */
    public function dropIndex(string $indexName, ?string $tableName = null): self;

    /**
     * Returns the raw SQL query string built so far.
     *
     * @return string The generated SQL query.
     */
    public function getQuery(): string;

    /**
     * Executes the built query and returns the first row or null.
     *
     * @return array<string, mixed>|null
     */
    public function first(): ?array;

    /**
     * Executes the built query and returns whether any row matches.
     */
    public function exists(): bool;

    /**
     * Executes the built query and returns the resulting rows.
     *
     * @return array<int, array<string, mixed>> Result set as an array of associative arrays.
     */
    public function execute(): array;

    /**
     * Executes a raw SQL statement with bindings and returns its rows.
     *
     * @param array<int, mixed> $bindings
     * @return array<int, array<string, mixed>>
     */
    public function statement(string $sql, array $bindings = []): array;
}
