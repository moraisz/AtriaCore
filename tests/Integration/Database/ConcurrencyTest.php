<?php

declare(strict_types=1);

use Atria\Async\Async;
use Atria\Async\EventLoop;
use Atria\Database\AbstractClasses\PooledConnection;
use Atria\Database\Connections\MySqlConnection;
use Atria\Database\Connections\PgSqlConnection;
use Atria\Database\Exceptions\QueryException;

/*
 * Concurrent queries on the pooled drivers. Runs with the same
 * DB_TEST_{PGSQL,MYSQL}_* variables as DriverTest and the pgsql or mysqli
 * extension.
 */

/**
 * @param array<string, mixed> $overrides
 */
function concurrencyConnection(string $driver, array $overrides = []): PooledConnection
{
    [$extension, $class] = $driver === 'pgsql' ? ['pgsql', PgSqlConnection::class] : ['mysqli', MySqlConnection::class];
    $prefix = 'DB_TEST_' . strtoupper($driver) . '_';
    $host = getenv($prefix . 'HOST');

    if (!extension_loaded($extension) || !is_string($host) || $host === '') {
        test()->markTestSkipped("{$driver}: extension or {$prefix}HOST missing");
    }

    EventLoop::instance()->reset();

    return new $class($overrides + [
        'host' => $host,
        'port' => (string) getenv($prefix . 'PORT'),
        'database' => (string) getenv($prefix . 'DATABASE'),
        'username' => (string) getenv($prefix . 'USERNAME'),
        'password' => (string) getenv($prefix . 'PASSWORD'),
    ]);
}

/**
 * Query that waits the given seconds and returns the server connection id as `conn`.
 */
function sleepQuery(string $driver): string
{
    return $driver === 'pgsql'
        ? 'SELECT pg_backend_pid() AS conn, pg_sleep(?) AS slept'
        : 'SELECT CONNECTION_ID() AS conn, SLEEP(?) AS slept';
}

/**
 * Runs the callback and returns how long it took, in seconds.
 */
function elapsed(Closure $callback): float
{
    $start = hrtime(true);
    $callback();

    return (hrtime(true) - $start) / 1e9;
}

dataset('pooled drivers', ['pgsql', 'mysql']);

test('independent queries run at the same time on separate connections', function (string $driver) {
    $db = concurrencyConnection($driver);

    // Open the pool first so connection time does not count.
    Async::concurrently(...array_fill(0, 3, fn() => $db->execute('SELECT 1', [])));

    $connections = [];
    $time = elapsed(function () use ($db, $driver, &$connections): void {
        $connections = Async::concurrently(...array_fill(0, 3, fn() => $db->execute(sleepQuery($driver), [0.2])->rows[0]['conn']));
    });

    expect($time)->toBeLessThan(0.45)
        ->and(array_unique($connections))->toHaveCount(3);
})->with('pooled drivers');

test('pool_size caps the number of connections', function (string $driver) {
    $db = concurrencyConnection($driver, ['pool_size' => 2]);
    $connections = [];

    $time = elapsed(function () use ($db, $driver, &$connections): void {
        $connections = Async::concurrently(...array_fill(0, 4, fn() => $db->execute(sleepQuery($driver), [0.1])->rows[0]['conn']));
    });

    expect(array_unique($connections))->toHaveCount(2)
        ->and($time)->toBeGreaterThanOrEqual(0.2);
})->with('pooled drivers');

test('values come back with PHP types inside and outside tasks', function (string $driver) {
    $db = concurrencyConnection($driver);
    $sql = $driver === 'pgsql'
        ? 'SELECT ?::int AS i, ?::bigint AS big, ?::float8 AS f, ?::numeric AS n, ?::text AS t, NULL::int AS missing'
        : 'SELECT CAST(? AS SIGNED) AS i, CAST(? AS SIGNED) AS big, CAST(? AS DOUBLE) AS f, CAST(? AS DECIMAL(10,2)) AS n, CAST(? AS CHAR) AS t, NULL AS missing';
    $bindings = [7, 9000000000, 1.5, '10.25', 'x'];
    $expected = ['i' => 7, 'big' => 9000000000, 'f' => 1.5, 'n' => '10.25', 't' => 'x', 'missing' => null];

    expect($db->execute($sql, $bindings)->rows[0])->toBe($expected)
        ->and(Async::run(fn() => $db->execute($sql, $bindings)->rows[0])->await())->toBe($expected);
})->with('pooled drivers');

test('strings reach the server intact inside tasks', function (string $driver) {
    $db = concurrencyConnection($driver);
    $tricky = "O'Brien \\ \"quoted\" ção 🚀 ' OR '1'='1";

    $value = Async::run(fn() => $db->execute('SELECT ? AS v', [$tricky])->rows[0]['v'])->await();

    expect($value)->toBe($tricky);
})->with('pooled drivers');

test('query errors carry the SQLSTATE and leave the connection usable', function (string $driver) {
    $db = concurrencyConnection($driver);

    foreach ([false, true] as $inTask) {
        $run = fn() => $db->execute('SELECT * FROM missing_concurrency_table', []);

        try {
            $inTask ? Async::run($run)->await() : $run();
            $this->fail('Expected a QueryException');
        } catch (QueryException $e) {
            expect($e->sqlState())->toBe($driver === 'pgsql' ? '42P01' : '42S02');
        }
    }

    expect($db->execute('SELECT 1 AS one', [])->rows)->toBe([['one' => 1]]);
})->with('pooled drivers');

test('a transaction inside a task keeps its own connection', function (string $driver) {
    $db = concurrencyConnection($driver);
    $db->execute('DROP TABLE IF EXISTS concurrency_tx_items', []);
    $db->execute('CREATE TABLE concurrency_tx_items (task int NOT NULL)', []);

    Async::concurrently(
        ...array_map(static fn(int $task): Closure => static function () use ($db, $driver, $task): void {
            $db->beginTransaction();
            $db->execute('INSERT INTO concurrency_tx_items (task) VALUES (?)', [$task]);
            $db->execute(sleepQuery($driver), [0.05]);

            if ($task === 2) {
                $db->rollback();
                return;
            }

            $db->commit();
        }, [1, 2, 3]),
    );

    $tasks = array_column($db->execute('SELECT task FROM concurrency_tx_items ORDER BY task', [])->rows, 'task');

    expect($tasks)->toBe([1, 3])
        ->and($db->inTransaction())->toBeFalse();

    $db->execute('DROP TABLE concurrency_tx_items', []);
})->with('pooled drivers');

test('lastInsertId belongs to the task that inserted', function (string $driver) {
    $db = concurrencyConnection($driver);
    $db->execute('DROP TABLE IF EXISTS concurrency_ids', []);
    $db->execute(
        $driver === 'pgsql'
            ? 'CREATE TABLE concurrency_ids (id SERIAL PRIMARY KEY, name text NOT NULL)'
            : 'CREATE TABLE concurrency_ids (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(10) NOT NULL)',
        [],
    );

    $ids = Async::concurrently(...array_map(
        static fn(string $name): Closure => static function () use ($db, $name): array {
            $db->execute('INSERT INTO concurrency_ids (name) VALUES (?)', [$name]);

            return [$name, $db->lastInsertId()];
        },
        ['a', 'b', 'c'],
    ));

    foreach ($ids as [$name, $id]) {
        expect($db->execute('SELECT name FROM concurrency_ids WHERE id = ?', [(int) $id])->rows[0]['name'] ?? null)->toBe($name);
    }

    $db->execute('DROP TABLE concurrency_ids', []);
})->with('pooled drivers');

test('concurrent queries are refused while the main flow holds a transaction', function (string $driver) {
    $db = concurrencyConnection($driver);
    $db->beginTransaction();

    try {
        expect(fn() => Async::concurrently(fn() => $db->execute('SELECT 1', [])))
            ->toThrow(LogicException::class, 'transaction is open');
    } finally {
        $db->rollback();
    }
})->with('pooled drivers');

test('reset closes connections left mid-query and keeps the pool usable', function (string $driver) {
    $db = concurrencyConnection($driver);
    $loop = EventLoop::instance();

    // A request that fails while a task still waits for its query.
    $loop->async(fn() => $db->execute(sleepQuery($driver), [5]));
    $loop->delay(0.05, static fn() => null);
    $loop->tick();
    $loop->tick();

    $loop->reset();
    $db->reset();

    expect($db->execute('SELECT 1 AS one', [])->rows)->toBe([['one' => 1]]);
})->with('pooled drivers');
