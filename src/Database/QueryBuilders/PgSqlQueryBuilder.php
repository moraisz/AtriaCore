<?php

declare(strict_types=1);

namespace Atria\Database\QueryBuilders;

use Atria\Database\AbstractClasses\SqlQueryBuilder;

class PgSqlQueryBuilder extends SqlQueryBuilder
{
    public function getQuery(): string
    {
        $parts = array_filter([
            $this->buildSelectClause(),
            $this->buildUpdateClause(),
            $this->buildDeleteClause(),
            $this->buildWhereClause(),
            $this->buildOrderByLimitOffsetClause(),
        ]);

        $sql = implode(' ', $parts);

        // INSERT and CREATE/DROP TABLE are mutually exclusive with the above in this design
        $insert = $this->buildInsertClause();
        if ($insert !== '') {
            $sql = $insert . $this->buildReturningClause(true);
        }

        if ($insert === '' && $sql !== '' && ($this->updateTable !== '' || $this->deleteFrom !== '')) {
            $sql .= $this->buildReturningClause();
        }

        $createTable = $this->buildCreateTableClause();
        if ($createTable !== '') {
            $sql = $createTable;
        }

        $dropTable = $this->buildDropTableClause();
        if ($dropTable !== '') {
            $sql = $dropTable;
        }

        return $sql;
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
        $this->dbConnection->execute("CREATE UNIQUE INDEX {$indexName} ON {$tableName} ({$cols})", []);
        return $this;
    }

    public function dropIndex(string $indexName, ?string $tableName = null): self
    {
        $this->dbConnection->execute("DROP INDEX IF EXISTS {$indexName}", []);
        return $this;
    }
}
