<?php

declare(strict_types=1);

namespace Atria\Database\Schema;

/**
 * A column declared on a Blueprint. Columns are NOT NULL unless nullable().
 */
class ColumnDefinition
{
    public bool $nullable = false;
    public bool $hasDefault = false;
    public mixed $default = null;
    public bool $unique = false;
    public bool $useCurrent = false;
    public ?string $referencesTable = null;
    public string $referencesColumn = 'id';
    public ?string $onDelete = null;

    /**
     * @param array<string, int> $parameters Type parameters such as length, precision and scale.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly array $parameters = [],
    ) {}

    public function nullable(bool $nullable = true): self
    {
        $this->nullable = $nullable;
        return $this;
    }

    public function default(mixed $value): self
    {
        $this->hasDefault = true;
        $this->default = $value;
        return $this;
    }

    public function unique(): self
    {
        $this->unique = true;
        return $this;
    }

    public function useCurrent(): self
    {
        $this->useCurrent = true;
        return $this;
    }

    public function references(string $table, string $column = 'id'): self
    {
        $this->referencesTable = $table;
        $this->referencesColumn = $column;
        return $this;
    }

    public function cascadeOnDelete(): self
    {
        $this->onDelete = 'CASCADE';
        return $this;
    }

    public function nullOnDelete(): self
    {
        $this->onDelete = 'SET NULL';
        return $this;
    }
}
