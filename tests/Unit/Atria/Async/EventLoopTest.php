<?php

declare(strict_types=1);

use Atria\Async\Async;
use Atria\Async\EventLoop;
use Atria\Async\Future;

beforeEach(function () {
    EventLoop::instance()->reset();
});

/**
 * Suspends the current Async::run() task for the given seconds.
 */
function asyncSleep(EventLoop $loop, float $seconds): void
{
    $suspension = $loop->getSuspension();
    $loop->delay($seconds, fn() => $suspension->resume());
    $suspension->suspend();
}

it('runs deferred callbacks before timers, and timers by due time', function () {
    $loop = new EventLoop();
    $order = [];

    $loop->delay(0.02, function () use (&$order) {
        $order[] = 'timer-20ms';
    });
    $loop->delay(0.01, function () use (&$order) {
        $order[] = 'timer-10ms';
    });
    $loop->defer(function () use (&$order) {
        $order[] = 'defer';
    });

    $loop->run();

    expect($order)->toBe(['defer', 'timer-10ms', 'timer-20ms'])
        ->and($loop->hasPendingWork())->toBeFalse();
});

it('skips cancelled callbacks and timers', function () {
    $loop = new EventLoop();
    $ran = [];

    $deferId = $loop->defer(function () use (&$ran) {
        $ran[] = 'defer';
    });
    $timerId = $loop->delay(0.001, function () use (&$ran) {
        $ran[] = 'timer';
    });

    $loop->cancel($deferId);
    $loop->cancel($timerId);
    $loop->run();

    expect($ran)->toBe([]);
});

it('runs readable watchers when the stream has data', function () {
    $loop = new EventLoop();
    [$reader, $writer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $received = null;

    $loop->onReadable($reader, function (string $id, $stream) use ($loop, &$received) {
        $received = fread($stream, 5);
        $loop->cancel($id);
    });
    $loop->delay(0.005, function () use ($writer) {
        fwrite($writer, 'hello');
    });

    $loop->run();

    expect($received)->toBe('hello');
});

it('blocks outside a Fiber until the awaited future completes', function () {
    $loop = EventLoop::instance();

    $future = Async::run(function () use ($loop): string {
        asyncSleep($loop, 0.005);

        return 'done';
    });

    expect($future)->toBeInstanceOf(Future::class)
        ->and($future->isComplete())->toBeFalse()
        ->and($future->await())->toBe('done');
});

it('runs tasks concurrently and keeps their keys', function () {
    $loop = EventLoop::instance();
    $start = hrtime(true);

    $results = Async::concurrently(
        first: function () use ($loop): int {
            asyncSleep($loop, 0.05);

            return 1;
        },
        second: function () use ($loop): int {
            asyncSleep($loop, 0.05);

            return 2;
        },
    );

    $elapsed = (hrtime(true) - $start) / 1e9;

    expect($results)->toBe(['first' => 1, 'second' => 2])
        ->and($elapsed)->toBeLessThan(0.09);
});

it('rethrows the first failure only after every task has finished', function () {
    $loop = EventLoop::instance();
    $finished = false;

    $error = null;

    try {
        Async::concurrently(
            function (): never {
                throw new RuntimeException('first');
            },
            function () use ($loop, &$finished): void {
                asyncSleep($loop, 0.005);
                $finished = true;
            },
            function (): never {
                throw new LogicException('third');
            },
        );
    } catch (Throwable $e) {
        $error = $e;
    }

    expect($error)->toBeInstanceOf(RuntimeException::class)
        ->and($error?->getMessage())->toBe('first')
        ->and($finished)->toBeTrue();
});

it('awaits futures from inside other tasks', function () {
    $inner = Async::run(fn(): string => 'inner');

    $outer = Async::run(fn(): string => $inner->await() . '+outer');

    expect($outer->await())->toBe('inner+outer');
});

it('does not suspend Fibers it did not create', function () {
    $loop = EventLoop::instance();
    $future = Async::run(function () use ($loop): string {
        asyncSleep($loop, 0.001);

        return 'ok';
    });

    $fiber = new Fiber(fn(): string => $future->await());

    expect($fiber->start())->toBeNull()
        ->and($fiber->isTerminated())->toBeTrue()
        ->and($fiber->getReturn())->toBe('ok');
});

it('refuses to block inside a loop callback', function () {
    $loop = EventLoop::instance();
    $error = null;

    $loop->defer(function () use ($loop, &$error) {
        try {
            $loop->getSuspension()->suspend();
        } catch (LogicException $e) {
            $error = $e;
        }
    });

    $loop->run();

    expect($error)->toBeInstanceOf(LogicException::class);
});

it('fails instead of hanging when nothing can resume the suspension', function () {
    $loop = new EventLoop();

    expect(fn() => $loop->getSuspension()->suspend())
        ->toThrow(LogicException::class, 'Event loop stopped');
});

it('drops pending work and destroys suspended tasks on reset', function () {
    $loop = EventLoop::instance();
    $cleanedUp = false;

    Async::run(function () use ($loop, &$cleanedUp): void {
        $suspension = $loop->getSuspension();
        $loop->delay(10, fn() => $suspension->resume());

        try {
            $suspension->suspend();
        } finally {
            $cleanedUp = true;
        }
    });
    // Wakes the first tick up quickly instead of waiting for the task's 10s timer.
    $loop->delay(0.001, fn() => null);

    $loop->tick();
    $loop->reset();
    gc_collect_cycles();

    expect($loop->hasPendingWork())->toBeFalse()
        ->and($cleanedUp)->toBeTrue();
});
