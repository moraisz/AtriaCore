<?php

declare(strict_types=1);

use Atria\Database\QueryBuilders\MySqlQueryBuilder;
use Atria\Database\QueryBuilders\PgSqlQueryBuilder;
use Atria\Database\QueryBuilders\SqliteQueryBuilder;

test('offset without limit uses the dialect unbounded limit', function (string $builder, string $expected) {
    /** @var PgSqlQueryBuilder|SqliteQueryBuilder|MySqlQueryBuilder $qb */
    $qb = new $builder(new MockDatabaseConnection());

    expect($qb->select(['*'])->from('users')->offset(10)->getQuery())->toBe($expected);
})->with([
    'pgsql' => [PgSqlQueryBuilder::class, 'SELECT * FROM users OFFSET 10'],
    'sqlite' => [SqliteQueryBuilder::class, 'SELECT * FROM users LIMIT -1 OFFSET 10'],
    'mysql' => [MySqlQueryBuilder::class, 'SELECT * FROM users LIMIT 18446744073709551615 OFFSET 10'],
]);

test('sqlite inserts use RETURNING', function () {
    $qb = new SqliteQueryBuilder(new MockDatabaseConnection());

    expect($qb->insertInto('users', ['name'])->values(['Ana'])->getQuery())
        ->toBe('INSERT INTO users (name) VALUES (?) RETURNING *');
});

test('mysql single-row insert reads the row back through lastInsertId', function () {
    $connection = new MockDatabaseConnection();
    $connection->nextInsertId = '42';
    $connection->setReturnRows([['id' => 42, 'name' => 'Ana']]);

    $row = new MySqlQueryBuilder($connection)->insertInto('users', ['name'])->values(['Ana'])->execute();

    expect($row)->toBe([['id' => 42, 'name' => 'Ana']]);
    expect($connection->executedQueries)->toBe([
        'INSERT INTO users (name) VALUES (?)',
        'SELECT * FROM users WHERE id = ?',
    ]);
    expect($connection->executedBindings[1])->toBe(['42']);
});

test('mysql multi-row insert returns no rows', function () {
    $connection = new MockDatabaseConnection();
    $connection->nextInsertId = '42';

    $rows = new MySqlQueryBuilder($connection)->insertInto('users', ['name'])->values(['Ana'])->values(['Bia'])->execute();

    expect($rows)->toBe([]);
    expect($connection->executedQueries)->toBe(['INSERT INTO users (name) VALUES (?), (?)']);
});

test('mysql rejects explicit RETURNING', function () {
    $qb = new MySqlQueryBuilder(new MockDatabaseConnection());

    $qb->update('users')->set(['name' => 'Ana'])->where('id', '=', 1)->returning(['id'])->getQuery();
})->throws(LogicException::class, 'does not support RETURNING');

test('mysql index statements', function () {
    $connection = new MockDatabaseConnection();
    $qb = new MySqlQueryBuilder($connection);

    $qb->createIndex('users_email_index', 'users', ['email']);
    $qb->createUniqueIndex('users_email_unique', 'users', ['email']);
    $qb->dropIndex('users_email_index', 'users');

    expect($connection->executedQueries)->toBe([
        'CREATE INDEX users_email_index ON users (email)',
        'CREATE UNIQUE INDEX users_email_unique ON users (email)',
        'DROP INDEX users_email_index ON users',
    ]);
});

test('mysql dropIndex requires the table name', function () {
    new MySqlQueryBuilder(new MockDatabaseConnection())->dropIndex('users_email_index');
})->throws(InvalidArgumentException::class);

test('sqlite index statements are idempotent', function () {
    $connection = new MockDatabaseConnection();
    $qb = new SqliteQueryBuilder($connection);

    $qb->createUniqueIndex('users_email_unique', 'users', ['email']);
    $qb->dropIndex('users_email_unique');

    expect($connection->executedQueries)->toBe([
        'CREATE UNIQUE INDEX IF NOT EXISTS users_email_unique ON users (email)',
        'DROP INDEX IF EXISTS users_email_unique',
    ]);
});

test('affected returns the statement row count', function () {
    $connection = new MockDatabaseConnection();
    $connection->affectedRows = [3];

    $count = new PgSqlQueryBuilder($connection)->update('users')->set(['active' => false])->affected();

    expect($count)->toBe(3);
    expect($connection->executedQueries)->toBe(['UPDATE users SET active = ?']);
});

test('transaction commits on success and rolls back on failure', function () {
    $connection = new MockDatabaseConnection();
    $qb = new PgSqlQueryBuilder($connection);

    expect($qb->transaction(fn() => 'done'))->toBe('done');
    expect($connection->inTransaction())->toBeFalse();

    expect(fn() => $qb->transaction(function () use ($connection): void {
        expect($connection->inTransaction())->toBeTrue();
        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class, 'boom');
    expect($connection->inTransaction())->toBeFalse();
});

test('nested transaction joins the open one', function () {
    $connection = new MockDatabaseConnection();
    $qb = new PgSqlQueryBuilder($connection);

    $qb->transaction(function ($outer) use ($connection): void {
        $outer->transaction(fn() => null);
        expect($connection->inTransaction())->toBeTrue();
    });

    expect($connection->inTransaction())->toBeFalse();
});
