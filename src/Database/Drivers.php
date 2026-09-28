<?php

declare(strict_types=1);

namespace Atria\Database;

use Atria\Database\Connections\MySqlConnection;
use Atria\Database\Connections\PgSqlConnection;
use Atria\Database\Connections\SqliteConnection;
use Atria\Database\Contracts\DatabaseConnection;
use Atria\Database\Contracts\QueryBuilder;
use Atria\Database\QueryBuilders\MySqlQueryBuilder;
use Atria\Database\QueryBuilders\PgSqlQueryBuilder;
use Atria\Database\QueryBuilders\SqliteQueryBuilder;
use Atria\Database\Schema\Grammars\MySqlSchemaGrammar;
use Atria\Database\Schema\Grammars\PgSqlSchemaGrammar;
use Atria\Database\Schema\Grammars\SchemaGrammar;
use Atria\Database\Schema\Grammars\SqliteSchemaGrammar;

class Drivers
{
    private const MYSQL = [
        'connection' => MySqlConnection::class,
        'query_builder' => MySqlQueryBuilder::class,
        'schema_grammar' => MySqlSchemaGrammar::class,
    ];

    /**
     * @var array<string, array{connection: class-string<DatabaseConnection>, query_builder: class-string<QueryBuilder>, schema_grammar: class-string<SchemaGrammar>}>
     */
    private const MAP = [
        'pgsql' => [
            'connection' => PgSqlConnection::class,
            'query_builder' => PgSqlQueryBuilder::class,
            'schema_grammar' => PgSqlSchemaGrammar::class,
        ],
        'sqlite' => [
            'connection' => SqliteConnection::class,
            'query_builder' => SqliteQueryBuilder::class,
            'schema_grammar' => SqliteSchemaGrammar::class,
        ],
        'mysql' => self::MYSQL,
        'mariadb' => self::MYSQL,
    ];

    /**
     * @return array{connection: class-string<DatabaseConnection>, query_builder: class-string<QueryBuilder>, schema_grammar: class-string<SchemaGrammar>}|null
     */
    public static function resolve(string $driver): ?array
    {
        return self::MAP[$driver] ?? null;
    }
}
