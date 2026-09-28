<?php

declare(strict_types=1);

namespace Atria\Database\QueryBuilders;

use Atria\Database\AbstractClasses\SqlQueryBuilder;

/**
 * MySQL/MariaDB dialect. Without RETURNING, a single-row INSERT is read back
 * through its generated `id`; explicit returning() calls are rejected.
 */
class MySqlQueryBuilder extends SqlQueryBuilder
{
    protected function supportsReturning(): bool
    {
        return false;
    }

    protected function unboundedLimit(): ?string
    {
        return '18446744073709551615';
    }

    public function createIndex(string $indexName, string $tableName, array $columns): self
    {
        $cols = implode(', ', $columns);
        $this->dbConnection->execute("CREATE INDEX {$indexName} ON {$tableName} ({$cols})", []);
        return $this;
    }

    public function createUniqueIndex(string $indexName, string $tableName, array $columns): self
    {
        $cols = implode(', ', $columns);
        $this->dbConnection->execute("CREATE UNIQUE INDEX {$indexName} ON {$tableName} ({$cols})", []);
        return $this;
    }

    public function dropIndex(string $indexName, ?string $tableName = null): self
    {
        if ($tableName === null || $tableName === '') {
            throw new \InvalidArgumentException('MySQL requires the table name to drop an index');
        }

        $this->dbConnection->execute("DROP INDEX {$indexName} ON {$tableName}", []);
        return $this;
    }
}
