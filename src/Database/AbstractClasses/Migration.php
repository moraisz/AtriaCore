<?php

declare(strict_types=1);

namespace Atria\Database\AbstractClasses;

use Atria\Database\Contracts\QueryBuilder;

abstract class Migration
{
    protected QueryBuilder $queryBuilder;

    public function setQueryBuilder(QueryBuilder $queryBuilder): void
    {
        $this->queryBuilder = $queryBuilder;
    }

    abstract public function up(): void;
    abstract public function down(): void;
}
