<?php

declare(strict_types=1);

namespace Atria\Database\Schema\Grammars;

use Atria\Database\Schema\ColumnDefinition;

class SqliteSchemaGrammar extends SchemaGrammar
{
    protected function type(ColumnDefinition $column): string
    {
        return match ($column->type) {
            'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            'integer', 'bigInteger', 'foreignId', 'boolean' => 'INTEGER',
            'string' => $this->varcharType($column),
            'text', 'json' => 'TEXT',
            'decimal' => $this->decimalType($column),
            'date' => 'DATE',
            'timestamp' => 'DATETIME',
            default => throw new \InvalidArgumentException("Unsupported column type: {$column->type}"),
        };
    }
}
