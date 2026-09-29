<?php

declare(strict_types=1);

namespace Atria\Database\QueryBuilders;

use Atria\Database\AbstractClasses\SqlQueryBuilder;

class SqliteQueryBuilder extends SqlQueryBuilder
{
    protected function unboundedLimit(): ?string
    {
        return '-1';
    }

    public function createIndex(string $indexName, string $tableName, array $columns): self
    {
        $cols = implode(', ', $columns);
        $this->dbConnection->execute("CREATE INDEX IF NOT EXISTS {$indexName} ON {$tableName} ({$cols})", []);
        return $this;
    }

    public function createUniqueIndex(string $indexName, string $tableName, array $columns): self
    {
        $cols = implode(', ', $columns);
        $this->dbConnection->execute("CREATE UNIQUE INDEX IF NOT EXISTS {$indexName} ON {$tableName} ({$cols})", []);
        return $this;
    }

    public function dropIndex(string $indexName, ?string $tableName = null): self
    {
        $this->dbConnection->execute("DROP INDEX IF EXISTS {$indexName}", []);
        return $this;
    }
}
