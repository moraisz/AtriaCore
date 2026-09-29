<?php

declare(strict_types=1);

namespace Atria\Database\Schema\Grammars;

use Atria\Database\Schema\ColumnDefinition;

class MySqlSchemaGrammar extends SchemaGrammar
{
    protected function type(ColumnDefinition $column): string
    {
        return match ($column->type) {
            'id' => 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY',
            'integer' => 'INT',
            'bigInteger' => 'BIGINT',
            'foreignId' => 'BIGINT UNSIGNED',
            'string' => $this->varcharType($column),
            'text' => 'TEXT',
            'boolean' => 'TINYINT(1)',
            'decimal' => $this->decimalType($column),
            'date' => 'DATE',
            'timestamp' => 'DATETIME',
            'json' => 'JSON',
            default => throw new \InvalidArgumentException("Unsupported column type: {$column->type}"),
        };
    }

    protected function indexesInsideTable(): bool
    {
        return true;
    }

    protected function tableOptions(): string
    {
        return 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    }
}
