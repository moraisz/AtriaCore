<?php

declare(strict_types=1);

use Atria\Database\Schema\Grammars\PgSqlSchemaGrammar;
use Atria\Database\Schema\Schema;

function runAuthMigration(string $file, MockDatabaseConnection $connection): void
{
    $migration = require dirname(__DIR__, 4) . '/src/Modules/Auth/Migrations/' . $file;
    $migration->setSchema(new Schema($connection, new PgSqlSchemaGrammar()));
    $migration->up();
}

test('users migration creates the users table with password_hash', function () {
    $connection = new MockDatabaseConnection();

    runAuthMigration('0000_00_00_000000_create_users_table.php', $connection);

    expect($connection->executedQueries)->toHaveCount(1);
    expect($connection->executedQueries[0])->toContain('CREATE TABLE IF NOT EXISTS users');
    expect($connection->executedQueries[0])->toContain('password_hash VARCHAR(255) NOT NULL');
    expect($connection->executedQueries[0])->toContain('email VARCHAR(100) NOT NULL UNIQUE');
});

test('refresh tokens migration executes the table and index statements once each', function () {
    $connection = new MockDatabaseConnection();

    runAuthMigration('0000_00_00_000001_create_refresh_tokens_table.php', $connection);

    expect($connection->executedQueries)->toHaveCount(2);
    expect($connection->executedQueries[0])->toContain('CREATE TABLE IF NOT EXISTS refresh_tokens');
    expect($connection->executedQueries[0])->toContain('token_hash VARCHAR(255) NOT NULL UNIQUE');
    expect($connection->executedQueries[0])->toContain('revoked_at TIMESTAMP NULL');
    expect($connection->executedQueries[0])->toContain('FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE');
    expect($connection->executedQueries[1])->toBe('CREATE INDEX IF NOT EXISTS idx_refresh_tokens_user_id ON refresh_tokens (user_id)');
});
