<?php

declare(strict_types=1);

use Atria\Database\AbstractClasses\PdoConnection;
use Atria\Database\Contracts\DatabaseConnection;
use Atria\System\Container;

class SqliteMemoryConnection extends PdoConnection
{
    public int $connects = 0;
    public int $clock = 1_000;
    /** @var array<int, PDOException> */
    public array $failures = [];

    protected function dsn(): string
    {
        return 'sqlite::memory:';
    }

    protected function createPdo(): PDO
    {
        ++$this->connects;
        $pdo = parent::createPdo();
        $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT)');

        return $pdo;
    }

    protected function run(string $query, array $bindings): PDOStatement
    {
        $failure = array_shift($this->failures);
        if ($failure !== null) {
            throw $failure;
        }

        return parent::run($query, $bindings);
    }

    protected function now(): int
    {
        return $this->clock;
    }
}

function lostConnection(): PDOException
{
    $e = new PDOException('SQLSTATE[08006]: server closed the connection unexpectedly');
    $e->errorInfo = ['08006', 7, 'server closed the connection unexpectedly'];

    return $e;
}

function countItems(PdoConnection $connection): int
{
    $stmt = $connection->execute('SELECT COUNT(*) AS total FROM items');
    assert($stmt instanceof PDOStatement);

    return (int) $stmt->fetchColumn();
}

test('reset rolls back a leaked transaction and keeps the connection usable', function () {
    $connection = new SqliteMemoryConnection();
    $connection->beginTransaction();
    $connection->execute('INSERT INTO items (name) VALUES (?)', ['leaked']);

    $connection->reset();

    expect($connection->inTransaction())->toBeFalse();
    expect($connection->isConnected())->toBeTrue();
    expect(countItems($connection))->toBe(0);
    expect($connection->connects)->toBe(1);
});

test('reset keeps a healthy connection open', function () {
    $connection = new SqliteMemoryConnection();
    $connection->execute('INSERT INTO items (name) VALUES (?)', ['kept']);

    $connection->reset();

    expect($connection->isConnected())->toBeTrue();
    expect(countItems($connection))->toBe(1);
});

test('reset recycles the connection after max_lifetime', function () {
    $connection = new SqliteMemoryConnection(['max_lifetime' => 60]);
    $connection->connect();

    $connection->clock += 59;
    $connection->reset();
    expect($connection->isConnected())->toBeTrue();

    $connection->clock += 1;
    $connection->reset();
    expect($connection->isConnected())->toBeFalse();

    countItems($connection);
    expect($connection->connects)->toBe(2);
});

test('execute reconnects once when the connection was lost outside a transaction', function () {
    $connection = new SqliteMemoryConnection();
    $connection->connect();
    $connection->failures = [lostConnection()];

    expect(countItems($connection))->toBe(0);
    expect($connection->connects)->toBe(2);
});

test('execute does not retry twice when the connection keeps failing', function () {
    $connection = new SqliteMemoryConnection();
    $connection->failures = [lostConnection(), lostConnection()];

    expect(fn() => countItems($connection))->toThrow(PDOException::class, 'server closed the connection');
});

test('execute does not retry a lost connection inside a transaction', function () {
    $connection = new SqliteMemoryConnection();
    $connection->beginTransaction();
    $connection->failures = [lostConnection()];

    expect(fn() => countItems($connection))->toThrow(PDOException::class, 'server closed the connection');
    expect($connection->connects)->toBe(1);
});

test('execute does not retry query errors', function () {
    $connection = new SqliteMemoryConnection();

    expect(fn() => $connection->execute('SELECT * FROM missing_table'))->toThrow(PDOException::class);
    expect($connection->connects)->toBe(1);
});

test('container request flush rolls back the persistent connection', function () {
    $container = new Container();
    $container->singleton(DatabaseConnection::class, static fn(): DatabaseConnection => new SqliteMemoryConnection());

    /** @var SqliteMemoryConnection $connection */
    $connection = $container->make(DatabaseConnection::class);
    $connection->beginTransaction();

    $container->flushRequestScope();

    expect($container->make(DatabaseConnection::class))->toBe($connection);
    expect($connection->inTransaction())->toBeFalse();
});

test('execute binds parameters by their PHP type', function () {
    $connection = new SqliteMemoryConnection();

    $stmt = $connection->execute('SELECT typeof(?) AS b, typeof(?) AS i, typeof(?) AS n, typeof(?) AS s', [false, 7, null, 'x']);
    assert($stmt instanceof PDOStatement);

    expect($stmt->fetch())->toBe(['b' => 'integer', 'i' => 'integer', 'n' => 'null', 's' => 'text']);
});
