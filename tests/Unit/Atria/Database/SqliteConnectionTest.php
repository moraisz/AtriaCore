<?php

declare(strict_types=1);

use Atria\Database\Connections\SqliteConnection;
use Atria\Database\Exceptions\QueryException;

final class ClockedSqliteConnection extends SqliteConnection
{
    public int $clock = 1_000;

    protected function now(): int
    {
        return $this->clock;
    }
}

/**
 * @param array<string, mixed> $config
 */
function memorySqlite(array $config = []): ClockedSqliteConnection
{
    $db = new ClockedSqliteConnection($config + ['database' => ':memory:']);
    $db->execute('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT)');

    return $db;
}

function countSqliteItems(SqliteConnection $db): int
{
    $total = $db->execute('SELECT COUNT(*) AS total FROM items')->rows[0]['total'];

    return is_int($total) ? $total : -1;
}

test('reset rolls back a leaked transaction and keeps the connection', function () {
    $db = memorySqlite();
    $db->beginTransaction();
    $db->execute('INSERT INTO items (name) VALUES (?)', ['leaked']);

    $db->reset();

    expect($db->inTransaction())->toBeFalse()
        ->and($db->isConnected())->toBeTrue()
        ->and(countSqliteItems($db))->toBe(0);
});

test('reset recycles the connection after max_lifetime', function () {
    $db = memorySqlite(['max_lifetime' => 60]);

    $db->clock += 59;
    $db->reset();
    expect($db->isConnected())->toBeTrue();

    $db->clock += 1;
    $db->reset();
    expect($db->isConnected())->toBeFalse();
});

test('bindings keep their PHP types', function () {
    $db = memorySqlite();

    $row = $db->execute('SELECT typeof(?) AS b, typeof(?) AS i, typeof(?) AS f, typeof(?) AS n, typeof(?) AS s', [false, 7, 1.5, null, 'x'])->rows[0];

    expect($row)->toBe(['b' => 'integer', 'i' => 'integer', 'f' => 'real', 'n' => 'null', 's' => 'text']);
});

test('results come back with PHP types', function () {
    $db = memorySqlite();

    expect($db->execute('SELECT 7 AS i, 1.5 AS f, NULL AS n, ? AS s', ['x'])->rows)
        ->toBe([['i' => 7, 'f' => 1.5, 'n' => null, 's' => 'x']]);
});

test('writes run once and report affected rows and the inserted id', function () {
    $db = memorySqlite();

    $insert = $db->execute('INSERT INTO items (name) VALUES (?)', ['a']);
    $id = $db->lastInsertId();
    $db->execute('INSERT INTO items (name) VALUES (?), (?)', ['b', 'c']);
    $update = $db->execute('UPDATE items SET name = ?', ['z']);

    expect($insert->rows)->toBe([])
        ->and($insert->affectedRows)->toBe(1)
        ->and($id)->toBe('1')
        ->and($update->affectedRows)->toBe(3)
        ->and(countSqliteItems($db))->toBe(3);
});

test('a select reports its row count, not the last write', function () {
    $db = memorySqlite();
    $db->execute('INSERT INTO items (name) VALUES (?), (?)', ['a', 'b']);

    expect($db->execute('SELECT * FROM items WHERE name = ?', ['a'])->affectedRows)->toBe(1);
});

test('errors become QueryException', function () {
    $db = memorySqlite();

    expect(fn() => $db->execute('SELECT * FROM missing_table'))->toThrow(QueryException::class, 'no such table');
});

test('foreign keys are enforced', function () {
    $db = memorySqlite();
    $db->execute('CREATE TABLE children (id INTEGER PRIMARY KEY, item_id INTEGER NOT NULL REFERENCES items(id))');

    expect(fn() => $db->execute('INSERT INTO children (item_id) VALUES (?)', [99]))
        ->toThrow(QueryException::class, 'FOREIGN KEY');
});

test('transactions require a matching begin', function () {
    $db = memorySqlite();

    expect(fn() => $db->commit())->toThrow(QueryException::class, 'no active transaction');

    $db->beginTransaction();
    expect(fn() => $db->beginTransaction())->toThrow(QueryException::class, 'already an active transaction');
});

test('a write with RETURNING runs once', function () {
    $db = memorySqlite();

    $rows = $db->execute('INSERT INTO items (name) VALUES (?) RETURNING id, name', ['a'])->rows;

    expect($rows)->toBe([['id' => 1, 'name' => 'a']])
        ->and(countSqliteItems($db))->toBe(1);

    $db->beginTransaction();
    $db->execute('UPDATE items SET name = ? RETURNING id', ['b']);
    $db->rollback();

    expect($db->execute('SELECT name FROM items')->rows)->toBe([['name' => 'a']]);
});
