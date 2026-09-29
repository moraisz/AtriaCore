<?php

declare(strict_types=1);

namespace Atria\Database\Schema;

/**
 * Dialect-neutral table definition compiled by a SchemaGrammar.
 */
class Blueprint
{
    /** @var array<int, ColumnDefinition> */
    private array $columns = [];

    /** @var array<int, array{name: string, columns: array<int, string>, unique: bool}> */
    private array $indexes = [];

    public function __construct(public readonly string $table) {}

    /**
     * Auto-incrementing big integer primary key.
     */
    public function id(string $name = 'id'): ColumnDefinition
    {
        return $this->addColumn('id', $name);
    }

    public function integer(string $name): ColumnDefinition
    {
        return $this->addColumn('integer', $name);
    }

    public function bigInteger(string $name): ColumnDefinition
    {
        return $this->addColumn('bigInteger', $name);
    }

    /**
     * Big integer column matching the type of id(), for foreign keys.
     */
    public function foreignId(string $name): ColumnDefinition
    {
        return $this->addColumn('foreignId', $name);
    }

    public function string(string $name, int $length = 255): ColumnDefinition
    {
        return $this->addColumn('string', $name, ['length' => $length]);
    }

    public function text(string $name): ColumnDefinition
    {
        return $this->addColumn('text', $name);
    }

    public function boolean(string $name): ColumnDefinition
    {
        return $this->addColumn('boolean', $name);
    }

    public function decimal(string $name, int $precision = 8, int $scale = 2): ColumnDefinition
    {
        return $this->addColumn('decimal', $name, ['precision' => $precision, 'scale' => $scale]);
    }

    public function date(string $name): ColumnDefinition
    {
        return $this->addColumn('date', $name);
    }

    public function timestamp(string $name): ColumnDefinition
    {
        return $this->addColumn('timestamp', $name);
    }

    public function json(string $name): ColumnDefinition
    {
        return $this->addColumn('json', $name);
    }

    /**
     * Adds `created_at` (defaults to now) and nullable `updated_at`.
     */
    public function timestamps(): void
    {
        $this->timestamp('created_at')->useCurrent();
        $this->timestamp('updated_at')->nullable();
    }

    /**
     * @param array<int, string> $columns
     */
    public function index(array $columns, ?string $name = null): void
    {
        $this->addIndex($columns, $name, false);
    }

    /**
     * @param array<int, string> $columns
     */
    public function unique(array $columns, ?string $name = null): void
    {
        $this->addIndex($columns, $name, true);
    }

    /** @return array<int, ColumnDefinition> */
    public function columns(): array
    {
        return $this->columns;
    }

    /** @return array<int, array{name: string, columns: array<int, string>, unique: bool}> */
    public function indexes(): array
    {
        return $this->indexes;
    }

    /**
     * @param array<string, int> $parameters
     */
    private function addColumn(string $type, string $name, array $parameters = []): ColumnDefinition
    {
        $column = new ColumnDefinition($name, $type, $parameters);
        $this->columns[] = $column;

        return $column;
    }

    /**
     * @param array<int, string> $columns
     */
    private function addIndex(array $columns, ?string $name, bool $unique): void
    {
        $this->indexes[] = [
            'name' => $name ?? $this->table . '_' . implode('_', $columns) . ($unique ? '_unique' : '_index'),
            'columns' => $columns,
            'unique' => $unique,
        ];
    }
}
