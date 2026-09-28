<?php

declare(strict_types=1);

namespace Atria\Database\Schema\Grammars;

use Atria\Database\Schema\Blueprint;
use Atria\Database\Schema\ColumnDefinition;

abstract class SchemaGrammar
{
    /**
     * Returns the SQL type for a column, including any dialect-specific key clause.
     */
    abstract protected function type(ColumnDefinition $column): string;

    /**
     * @return array<int, string> CREATE TABLE followed by CREATE INDEX statements.
     */
    public function compileCreate(Blueprint $blueprint): array
    {
        $definitions = [];
        $foreignKeys = [];

        foreach ($blueprint->columns() as $column) {
            $definitions[] = $this->compileColumn($column);

            if ($column->referencesTable !== null) {
                $foreignKeys[] = $this->compileForeignKey($column);
            }
        }

        $inlineIndexes = [];
        $indexStatements = [];
        foreach ($blueprint->indexes() as $index) {
            if ($this->indexesInsideTable()) {
                $inlineIndexes[] = ($index['unique'] ? 'UNIQUE INDEX ' : 'INDEX ') . "{$index['name']} (" . implode(', ', $index['columns']) . ')';
            } else {
                $indexStatements[] = $this->compileIndex($blueprint->table, $index['name'], $index['columns'], $index['unique']);
            }
        }

        $body = implode(', ', array_merge($definitions, $foreignKeys, $inlineIndexes));
        $table = trim("CREATE TABLE IF NOT EXISTS {$blueprint->table} ({$body}) " . $this->tableOptions());

        return [$table, ...$indexStatements];
    }

    public function compileDrop(string $table): string
    {
        return "DROP TABLE IF EXISTS {$table}";
    }

    protected function compileColumn(ColumnDefinition $column): string
    {
        $sql = "{$column->name} {$this->type($column)}";

        if ($column->type === 'id') {
            return $sql;
        }

        $sql .= $column->nullable ? ' NULL' : ' NOT NULL';

        if ($column->useCurrent) {
            $sql .= ' DEFAULT CURRENT_TIMESTAMP';
        } elseif ($column->hasDefault) {
            $sql .= ' DEFAULT ' . $this->defaultValue($column->default);
        }

        if ($column->unique) {
            $sql .= ' UNIQUE';
        }

        return $sql;
    }

    protected function compileForeignKey(ColumnDefinition $column): string
    {
        $sql = "FOREIGN KEY ({$column->name}) REFERENCES {$column->referencesTable}({$column->referencesColumn})";

        if ($column->onDelete !== null) {
            $sql .= " ON DELETE {$column->onDelete}";
        }

        return $sql;
    }

    /**
     * @param array<int, string> $columns
     */
    protected function compileIndex(string $table, string $name, array $columns, bool $unique): string
    {
        $type = $unique ? 'UNIQUE INDEX' : 'INDEX';

        return "CREATE {$type} IF NOT EXISTS {$name} ON {$table} (" . implode(', ', $columns) . ')';
    }

    /**
     * Whether indexes are declared inside CREATE TABLE, for dialects without
     * CREATE INDEX IF NOT EXISTS.
     */
    protected function indexesInsideTable(): bool
    {
        return false;
    }

    protected function tableOptions(): string
    {
        return '';
    }

    protected function defaultValue(mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $this->booleanLiteral($value),
            is_int($value), is_float($value) => (string) $value,
            is_string($value) => "'" . str_replace("'", "''", $value) . "'",
            default => throw new \InvalidArgumentException('Unsupported column default value type: ' . get_debug_type($value)),
        };
    }

    protected function booleanLiteral(bool $value): string
    {
        return $value ? '1' : '0';
    }

    protected function decimalType(ColumnDefinition $column): string
    {
        return sprintf('DECIMAL(%d, %d)', $column->parameters['precision'] ?? 8, $column->parameters['scale'] ?? 2);
    }

    protected function varcharType(ColumnDefinition $column): string
    {
        return sprintf('VARCHAR(%d)', $column->parameters['length'] ?? 255);
    }
}
