<?php

declare(strict_types=1);

use Atria\Async\Async;
use Atria\Async\EventLoop;
use Atria\Database\AbstractClasses\PooledConnection;
use Atria\Database\Contracts\ConnectionLink;
use Atria\Database\Contracts\DatabaseConnection;
use Atria\Database\Exceptions\QueryException;
use Atria\Database\Result;
use Atria\System\Container;

/**
 * In-memory link: records statements, can fail on demand and, inside Async::run()
 * tasks, waits `latency` seconds on the event loop like a real server.
 */
final class FakeLink implements ConnectionLink
{
    /** @var list<string> */
    public array $statements = [];
    /** @var list<QueryException> */
    public array $failures = [];
    public bool $inTransaction = false;
    public bool $broken = false;
    public bool $busy = false;
    public bool $closed = false;
    public ?string $insertId = null;

    public function __construct(
        public readonly int $number,
        private readonly EventLoop $loop,
        private readonly int $openedAt,
        private readonly float $latency,
    ) {}

    public function query(string $sql, array $bindings): Result
    {
        $this->statements[] = $sql;

        $failure = array_shift($this->failures);
        if ($failure !== null) {
            $this->broken = $failure->isConnectionError();
            throw $failure;
        }

        if ($this->latency > 0 && $this->loop->inAsyncTask()) {
            $this->busy = true;
            $suspension = $this->loop->getSuspension();
            $this->loop->delay($this->latency, static fn() => $suspension->resume());
            $suspension->suspend();
            $this->busy = false;
        }

        if (str_starts_with($sql, 'INSERT')) {
            $this->insertId = (string) (count($this->statements) * 10 + $this->number);
        }

        return new Result([['link' => $this->number]], 1);
    }

    public function begin(): void
    {
        $this->query('BEGIN', []);
        $this->inTransaction = true;
    }

    public function commit(): void
    {
        $this->inTransaction = false;
        $this->query('COMMIT', []);
    }

    public function rollback(): void
    {
        $this->inTransaction = false;
        $this->query('ROLLBACK', []);
    }

    public function lastInsertId(): ?string
    {
        return $this->insertId;
    }

    public function isHealthy(): bool
    {
        return !$this->broken && !$this->busy && !$this->closed;
    }

    public function isIdle(): bool
    {
        return !$this->inTransaction;
    }

    public function rollbackBlocking(): bool
    {
        if (!$this->isHealthy()) {
            return false;
        }

        $this->inTransaction = false;
        $this->statements[] = 'ROLLBACK';

        return true;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function connectedAt(): int
    {
        return $this->openedAt;
    }
}

final class FakePooledConnection extends PooledConnection
{
    /** @var list<FakeLink> */
    public array $opened = [];
    public int $clock = 1_000;
    public float $latency = 0.0;
    /** @var list<QueryException> Failures handed to the next opened link. */
    public array $nextLinkFailures = [];

    protected function requiredExtension(): ?string
    {
        return null;
    }

    protected function openLink(): ConnectionLink
    {
        $link = new FakeLink(count($this->opened) + 1, $this->loop, $this->clock, $this->latency);
        $link->failures = $this->nextLinkFailures;
        $this->nextLinkFailures = [];
        $this->opened[] = $link;

        return $link;
    }

    protected function now(): int
    {
        return $this->clock;
    }
}

/**
 * @param array<string, mixed> $config
 */
function fakePool(array $config = []): FakePooledConnection
{
    EventLoop::instance()->reset();

    return new FakePooledConnection($config);
}

function lostConnection(): QueryException
{
    return new QueryException('server closed the connection unexpectedly', '08006');
}

test('sequential statements reuse one link', function () {
    $db = fakePool();

    $db->execute('SELECT 1');
    $db->execute('SELECT 2');

    expect($db->opened)->toHaveCount(1)
        ->and($db->opened[0]->statements)->toBe(['SELECT 1', 'SELECT 2']);
});

test('concurrent tasks borrow separate links up to pool_size', function () {
    $db = fakePool(['pool_size' => 2]);
    $db->latency = 0.02;

    $links = Async::concurrently(...array_fill(0, 4, fn() => $db->execute('SELECT 1')->rows[0]['link']));

    expect($db->opened)->toHaveCount(2)
        ->and(array_unique($links))->toHaveCount(2);
});

test('a transaction pins its link and reset rolls it back', function () {
    $db = fakePool();
    $db->beginTransaction();
    $db->execute('INSERT INTO t');

    $db->reset();

    expect($db->inTransaction())->toBeFalse()
        ->and($db->opened)->toHaveCount(1)
        ->and($db->opened[0]->statements)->toBe(['BEGIN', 'INSERT INTO t', 'ROLLBACK'])
        ->and($db->opened[0]->closed)->toBeFalse();
});

test('concurrent queries are refused while the main flow holds a transaction', function () {
    $db = fakePool();
    $db->beginTransaction();

    expect(fn() => Async::concurrently(fn() => $db->execute('SELECT 1')))
        ->toThrow(LogicException::class, 'transaction is open');
});

test('each task keeps its own transaction', function () {
    $db = fakePool();
    $db->latency = 0.01;

    Async::concurrently(
        function () use ($db): void {
            $db->beginTransaction();
            $db->execute('INSERT INTO a');
            $db->commit();
        },
        function () use ($db): void {
            $db->beginTransaction();
            $db->execute('INSERT INTO b');
            $db->rollback();
        },
    );

    $statements = array_map(static fn(FakeLink $link): array => $link->statements, $db->opened);

    expect($statements)->toContain(['BEGIN', 'INSERT INTO a', 'COMMIT'])
        ->and($statements)->toContain(['BEGIN', 'INSERT INTO b', 'ROLLBACK'])
        ->and($db->inTransaction())->toBeFalse();
});

test('a lost connection is retried once on a fresh link', function () {
    $db = fakePool();
    $db->connect();
    $db->opened[0]->failures = [lostConnection()];

    expect($db->execute('SELECT 1')->rows[0]['link'])->toBe(2)
        ->and($db->opened[0]->closed)->toBeTrue();
});

test('a lost connection is not retried twice', function () {
    $db = fakePool();
    $db->connect();
    $db->opened[0]->failures = [lostConnection()];
    $db->nextLinkFailures = [lostConnection()];

    expect(fn() => $db->execute('SELECT 1'))->toThrow(QueryException::class, 'server closed the connection');
});

test('a lost connection inside a transaction is not retried', function () {
    $db = fakePool();
    $db->beginTransaction();
    $db->opened[0]->failures = [lostConnection()];

    expect(fn() => $db->execute('SELECT 1'))->toThrow(QueryException::class, 'server closed the connection')
        ->and($db->opened)->toHaveCount(1);
});

test('query errors are not retried and keep the link', function () {
    $db = fakePool();
    $db->connect();
    $db->opened[0]->failures = [new QueryException('relation does not exist', '42P01')];

    expect(fn() => $db->execute('SELECT * FROM missing'))->toThrow(QueryException::class);
    expect($db->execute('SELECT 1')->rows[0]['link'])->toBe(1);
});

test('reset recycles links after max_lifetime', function () {
    $db = fakePool(['max_lifetime' => 60]);
    $db->connect();

    $db->clock += 59;
    $db->reset();
    expect($db->isConnected())->toBeTrue();

    $db->clock += 1;
    $db->reset();
    expect($db->isConnected())->toBeFalse()
        ->and($db->opened[0]->closed)->toBeTrue();

    $db->execute('SELECT 1');
    expect($db->opened)->toHaveCount(2);
});

test('reset closes links still borrowed by an unfinished task', function () {
    $db = fakePool();
    $db->latency = 60;
    $loop = EventLoop::instance();

    $loop->async(fn() => $db->execute('SELECT sleep'));
    $loop->delay(0.001, static fn() => null);
    $loop->tick();

    $loop->reset();
    $db->reset();

    expect($db->opened[0]->closed)->toBeTrue()
        ->and($db->isConnected())->toBeFalse();
});

test('lastInsertId reads the link that ran the insert', function () {
    $db = fakePool();
    $db->latency = 0.01;

    $ids = Async::concurrently(
        function () use ($db): ?string {
            $db->execute('INSERT INTO a');

            return $db->lastInsertId();
        },
        function () use ($db): ?string {
            $db->execute('INSERT INTO b');

            return $db->lastInsertId();
        },
    );

    expect($ids)->toBe([$db->opened[0]->insertId, $db->opened[1]->insertId])
        ->and($ids[0])->not->toBe($ids[1]);
});

test('container request flush resets the persistent pool', function () {
    $container = new Container();
    $container->singleton(DatabaseConnection::class, static fn(): DatabaseConnection => fakePool());

    /** @var FakePooledConnection $db */
    $db = $container->make(DatabaseConnection::class);
    $db->beginTransaction();

    $container->flushRequestScope();

    expect($container->make(DatabaseConnection::class))->toBe($db)
        ->and($db->inTransaction())->toBeFalse();
});
