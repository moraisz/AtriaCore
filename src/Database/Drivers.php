<?php

declare(strict_types=1);

namespace Atria\Database;

use Atria\Database\Contracts\DatabaseConnection;
use Atria\Database\Contracts\QueryBuilder;
use Atria\Database\Connections\PgSqlConnection;
use Atria\Database\QueryBuilders\PgSqlQueryBuilder;

class Drivers
{
    /**
     * @var array<string, array{connection: class-string<DatabaseConnection>, query_builder: class-string<QueryBuilder>}>
     */
    private const MAP = [
        'pgsql' => [
            'connection' => PgSqlConnection::class,
            'query_builder' => PgSqlQueryBuilder::class,
        ],
    ];

    /**
     * @return array{connection: class-string<DatabaseConnection>, query_builder: class-string<QueryBuilder>}|null
     */
    public static function resolve(string $driver): ?array
    {
        return self::MAP[$driver] ?? null;
    }
}
