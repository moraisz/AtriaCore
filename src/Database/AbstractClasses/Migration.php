<?php

declare(strict_types=1);

namespace Atria\Database\AbstractClasses;

use Atria\Database\Contracts\QueryBuilder;
use Atria\Database\Schema\Schema;

abstract class Migration
{
    protected QueryBuilder $queryBuilder;
    protected Schema $schema;

    public function setQueryBuilder(QueryBuilder $queryBuilder): void
    {
        $this->queryBuilder = $queryBuilder;
    }

    public function setSchema(Schema $schema): void
    {
        $this->schema = $schema;
    }

    abstract public function up(): void;
    abstract public function down(): void;
}
