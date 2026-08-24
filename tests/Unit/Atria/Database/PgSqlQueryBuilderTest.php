<?php

declare(strict_types=1);

use Atria\Database\QueryBuilders\PgSqlQueryBuilder;

test('getQuery composes SELECT with FROM and WHERE', function () {
    $conn = new MockDatabaseConnection();
    $qb = new PgSqlQueryBuilder($conn);

    $qb->select(['*'])->from('users')->where('id', '=', 42);
    $sql = $qb->getQuery();

    expect($sql)->toBe('SELECT * FROM users WHERE id = ?');
});

test('getQuery composes SELECT with multiple WHERE AND conditions', function () {
    $conn = new MockDatabaseConnection();
    $qb = new PgSqlQueryBuilder($conn);

    $qb->select(['id', 'name'])
        ->from('users')
        ->where('active', '=', 1)
        ->where('role', '=', 'admin');

    $sql = $qb->getQuery();

    expect($sql)->toBe('SELECT id, name FROM users WHERE active = ? AND role = ?');
});

test('getQuery composes SELECT with JOIN', function () {
    $conn = new MockDatabaseConnection();
    $qb = new PgSqlQueryBuilder($conn);

    $qb->select(['u.*', 'p.bio'])
        ->from('users u')
        ->join('profiles p', 'u.id', '=', 'p.user_id');

    $sql = $qb->getQuery();

    expect($sql)->toContain('INNER JOIN profiles p ON u.id = p.user_id');
});

test('getQuery composes SELECT with ORDER BY, LIMIT, OFFSET', function () {
    $conn = new MockDatabaseConnection();
    $qb = new PgSqlQueryBuilder($conn);

    $qb->select(['*'])
        ->from('users')
        ->orderBy('name', 'ASC')
        ->limit(10)
        ->offset(20);

    $sql = $qb->getQuery();

    expect($sql)->toBe('SELECT * FROM users ORDER BY name ASC LIMIT 10 OFFSET 20');
});

test('getQuery composes WHERE IN with placeholders', function () {
    $conn = new MockDatabaseConnection();
    $qb = new PgSqlQueryBuilder($conn);

    $qb->select(['*'])
        ->from('users')
        ->where('id', 'IN', [1, 2, 3]);

    $sql = $qb->getQuery();

    expect($sql)->toBe('SELECT * FROM users WHERE id IN (?, ?, ?)');
});

test('getQuery composes WHERE IS NULL and IS NOT NULL without bindings', function () {
    $conn = new MockDatabaseConnection();
    $qb = new PgSqlQueryBuilder($conn);

    $qb->select(['*'])
        ->from('refresh_tokens')
        ->whereNull('revoked_at')
        ->whereNotNull('expires_at');

    expect($qb->getQuery())->toBe('SELECT * FROM refresh_tokens WHERE revoked_at IS NULL AND expires_at IS NOT NULL');
});

test('getQuery composes INSERT with RETURNING *', function () {
    $conn = new MockDatabaseConnection();
    $qb = new PgSqlQueryBuilder($conn);

    $qb->insertInto('users', ['name', 'email'])
        ->values(['John', 'john@test.com']);

    $sql = $qb->getQuery();

    expect($sql)->toBe('INSERT INTO users (name, email) VALUES (?, ?) RETURNING *');
});

test('getQuery composes UPDATE', function () {
    $conn = new MockDatabaseConnection();
    $qb = new PgSqlQueryBuilder($conn);

    $qb->update('users')
        ->set(['name' => 'Jane'])
        ->where('id', '=', 1);

    $sql = $qb->getQuery();

    expect($sql)->toBe('UPDATE users SET name = ? WHERE id = ?');
});

test('getQuery composes UPDATE with RETURNING clause', function () {
    $conn = new MockDatabaseConnection();
    $qb = new PgSqlQueryBuilder($conn);

    $qb->update('refresh_tokens')
        ->set(['revoked_at' => '2026-01-01 00:00:00'])
        ->where('user_id', '=', 7)
        ->returning(['user_id', 'token_hash']);

    expect($qb->getQuery())->toBe('UPDATE refresh_tokens SET revoked_at = ? WHERE user_id = ? RETURNING user_id, token_hash');
});

test('getQuery composes DELETE with RETURNING clause', function () {
    $conn = new MockDatabaseConnection();
    $qb = new PgSqlQueryBuilder($conn);

    $qb->deleteFrom('refresh_tokens')
        ->where('expires_at', '<=', '2026-01-01 00:00:00')
        ->returning(['id']);

    expect($qb->getQuery())->toBe('DELETE FROM refresh_tokens WHERE expires_at <= ? RETURNING id');
});

test('getQuery composes DELETE', function () {
    $conn = new MockDatabaseConnection();
    $qb = new PgSqlQueryBuilder($conn);

    $qb->deleteFrom('users')
        ->where('id', '=', 99);

    $sql = $qb->getQuery();

    expect($sql)->toBe('DELETE FROM users WHERE id = ?');
});

test('getQuery composes CREATE TABLE', function () {
    $conn = new MockDatabaseConnection();
    $qb = new PgSqlQueryBuilder($conn);

    $qb->createTable('posts', [
        'id' => 'SERIAL PRIMARY KEY',
        'title' => 'VARCHAR(255) NOT NULL',
    ]);

    $sql = $qb->getQuery();

    expect($sql)->toBe('CREATE TABLE IF NOT EXISTS posts (id SERIAL PRIMARY KEY, title VARCHAR(255) NOT NULL)');
});

test('getQuery composes DROP TABLE', function () {
    $conn = new MockDatabaseConnection();
    $qb = new PgSqlQueryBuilder($conn);

    $qb->dropTable('legacy');

    $sql = $qb->getQuery();

    expect($sql)->toBe('DROP TABLE IF EXISTS legacy');
});

test('getQuery returns empty string when nothing built', function () {
    $conn = new MockDatabaseConnection();
    $qb = new PgSqlQueryBuilder($conn);

    expect($qb->getQuery())->toBe('');
});

test('execute delegates to dbConnection and returns rows', function () {
    $conn = new MockDatabaseConnection();
    $conn->setReturnRows([['id' => 1, 'name' => 'Test']]);
    $qb = new PgSqlQueryBuilder($conn);

    $qb->select(['*'])->from('users');
    $rows = $qb->execute();

    expect($rows)->toBe([['id' => 1, 'name' => 'Test']]);
    expect($conn->executedQueries)->toHaveCount(1);
    expect($conn->executedQueries[0])->toBe('SELECT * FROM users');
});

test('first returns the first row and resets state', function () {
    $conn = new MockDatabaseConnection();
    $conn->setReturnRows([
        ['id' => 1, 'name' => 'First'],
        ['id' => 2, 'name' => 'Second'],
    ]);
    $qb = new PgSqlQueryBuilder($conn);

    $row = $qb->select(['*'])->from('users')->orderBy('id')->first();

    expect($row)->toBe(['id' => 1, 'name' => 'First']);
    expect($conn->executedQueries[0])->toBe('SELECT * FROM users ORDER BY id ASC LIMIT 1');
    expect($qb->getQuery())->toBe('');
});

test('exists returns true when a row matches and resets state', function () {
    $conn = new MockDatabaseConnection();
    $conn->setReturnRows([['id' => 1]]);
    $qb = new PgSqlQueryBuilder($conn);

    $exists = $qb->select(['*'])->from('users')->where('email', '=', 'john@test.com')->exists();

    expect($exists)->toBeTrue();
    expect($conn->executedQueries[0])->toBe('SELECT * FROM users WHERE email = ? LIMIT 1');
    expect($qb->getQuery())->toBe('');
});

test('statement executes raw sql and returns rows', function () {
    $conn = new MockDatabaseConnection();
    $conn->setReturnRows([['user_id' => 7]]);
    $qb = new PgSqlQueryBuilder($conn);

    $rows = $qb->statement('SELECT user_id FROM users WHERE id = ?', [7]);

    expect($rows)->toBe([['user_id' => 7]]);
    expect($conn->executedQueries[0])->toBe('SELECT user_id FROM users WHERE id = ?');
    expect($conn->executedBindings[0])->toBe([7]);
    expect($qb->getQuery())->toBe('');
});

test('execute resets state after call', function () {
    $conn = new MockDatabaseConnection();
    $qb = new PgSqlQueryBuilder($conn);

    $qb->select(['*'])->from('users')->where('id', '=', 1);
    $qb->execute();

    expect($qb->getQuery())->toBe('');
});

test('where throws on invalid operator', function () {
    $conn = new MockDatabaseConnection();
    $qb = new PgSqlQueryBuilder($conn);

    $qb->where('id', '??', 1);
})->throws(\InvalidArgumentException::class);

test('createIndex executes raw SQL on connection', function () {
    $conn = new MockDatabaseConnection();
    $qb = new PgSqlQueryBuilder($conn);

    $qb->createIndex('idx_users_email', 'users', ['email']);

    expect($conn->executedQueries)->toHaveCount(1);
    expect($conn->executedQueries[0])->toBe('CREATE INDEX IF NOT EXISTS idx_users_email ON users (email)');
});

test('createUniqueIndex executes raw SQL on connection', function () {
    $conn = new MockDatabaseConnection();
    $qb = new PgSqlQueryBuilder($conn);

    $qb->createUniqueIndex('uq_users_email', 'users', ['email']);

    expect($conn->executedQueries)->toHaveCount(1);
    expect($conn->executedQueries[0])->toBe('CREATE UNIQUE INDEX uq_users_email ON users (email)');
});

test('dropIndex executes raw SQL on connection', function () {
    $conn = new MockDatabaseConnection();
    $qb = new PgSqlQueryBuilder($conn);

    $qb->dropIndex('idx_old');

    expect($conn->executedQueries)->toHaveCount(1);
    expect($conn->executedQueries[0])->toBe('DROP INDEX IF EXISTS idx_old');
});

test('fluent interface for chainable methods', function () {
    $conn = new MockDatabaseConnection();
    $qb = new PgSqlQueryBuilder($conn);

    expect($qb->select(['*']))->toBe($qb);
    expect($qb->from('t'))->toBe($qb);
    expect($qb->where('x', '=', 1))->toBe($qb);
    expect($qb->whereNull('deleted_at'))->toBe($qb);
    expect($qb->whereNotNull('created_at'))->toBe($qb);
    expect($qb->orderBy('x'))->toBe($qb);
    expect($qb->limit(5))->toBe($qb);
    expect($qb->offset(2))->toBe($qb);
    expect($qb->insertInto('t', ['x']))->toBe($qb);
    expect($qb->values([1]))->toBe($qb);
    expect($qb->returning(['id']))->toBe($qb);
    expect($qb->update('t'))->toBe($qb);
    expect($qb->set(['x' => 1]))->toBe($qb);
    expect($qb->deleteFrom('t'))->toBe($qb);
    expect($qb->createTable('t', ['x' => 'INT']))->toBe($qb);
    expect($qb->dropTable('t'))->toBe($qb);
    expect($qb->createIndex('i', 't', ['x']))->toBe($qb);
    expect($qb->createUniqueIndex('i', 't', ['x']))->toBe($qb);
    expect($qb->dropIndex('i'))->toBe($qb);
});

test('bindings are passed to connection execute', function () {
    $conn = new MockDatabaseConnection();
    $conn->setReturnRows([]);
    $qb = new PgSqlQueryBuilder($conn);

    $qb->select(['*'])
        ->from('users')
        ->where('id', '=', 42)
        ->where('active', '=', 1);

    $qb->execute();

    expect($conn->executedBindings)->toHaveCount(1);
    expect($conn->executedBindings[0])->toBe([42, 1]);
});

test('WHERE IN bindings are flattened into bindings array', function () {
    $conn = new MockDatabaseConnection();
    $conn->setReturnRows([]);
    $qb = new PgSqlQueryBuilder($conn);

    $qb->select(['*'])
        ->from('users')
        ->where('id', 'IN', [10, 20, 30]);

    $qb->execute();

    expect($conn->executedBindings[0])->toBe([10, 20, 30]);
});

test('WHERE NULL and NOT NULL do not append bindings', function () {
    $conn = new MockDatabaseConnection();
    $conn->setReturnRows([]);
    $qb = new PgSqlQueryBuilder($conn);

    $qb->select(['*'])
        ->from('refresh_tokens')
        ->whereNull('revoked_at')
        ->whereNotNull('expires_at')
        ->execute();

    expect($conn->executedBindings[0])->toBe([]);
});
