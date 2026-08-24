<?php

declare(strict_types=1);

use Atria\Database\QueryBuilders\PgSqlQueryBuilder;

test('users migration creates the users table with password_hash', function () {
    $connection = new MockDatabaseConnection();
    $queryBuilder = new PgSqlQueryBuilder($connection);
    $migration = require dirname(__DIR__, 4) . '/src/Modules/Auth/Migrations/0000_00_00_000000_create_users_table.php';
    $migration->setQueryBuilder($queryBuilder);

    $migration->up();

    expect($connection->executedQueries)->toHaveCount(1);
    expect($connection->executedQueries[0])->toContain('CREATE TABLE IF NOT EXISTS users');
    expect($connection->executedQueries[0])->toContain('password_hash');
    expect($connection->executedQueries[0])->toContain('email VARCHAR(100) UNIQUE NOT NULL');
});

test('refresh tokens migration executes the table and index statements once each', function () {
    $connection = new MockDatabaseConnection();
    $queryBuilder = new PgSqlQueryBuilder($connection);
    $migration = require dirname(__DIR__, 4) . '/src/Modules/Auth/Migrations/0000_00_00_000001_create_refresh_tokens_table.php';
    $migration->setQueryBuilder($queryBuilder);

    $migration->up();

    expect($connection->executedQueries)->toHaveCount(2);
    expect($connection->executedQueries[0])->toContain('CREATE TABLE IF NOT EXISTS refresh_tokens');
    expect($connection->executedQueries[0])->toContain('token_hash VARCHAR(255) UNIQUE NOT NULL');
    expect($connection->executedQueries[0])->toContain('revoked_at TIMESTAMP');
    expect($connection->executedQueries[1])->toContain('CREATE INDEX IF NOT EXISTS idx_refresh_tokens_user_id');
});
