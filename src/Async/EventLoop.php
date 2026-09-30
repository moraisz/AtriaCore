<?php

declare(strict_types=1);

namespace Atria\Async;

use Atria\System\Contracts\Resettable;
use Closure;
use Fiber;
use LogicException;
use SplPriorityQueue;
use WeakMap;

/**
 * Minimal Fiber-aware event loop built on stream_select().
 *
 * There is one loop per PHP thread. Code outside an `Async::run()` Fiber blocks by
 * running the loop until its Suspension resolves, so synchronous callers keep
 * working unchanged; code inside an `Async::run()` Fiber suspends and lets the loop
 * drive the other Fibers. reset() drops everything left over after a request.
 */
final class EventLoop implements Resettable
{
    private static ?self $instance = null;

    /** @var array<string, Closure(string): void> */
    private array $deferred = [];

    /** @var array<string, array{at: float, callback: Closure(string): void}> */
    private array $timers = [];

    /** @var SplPriorityQueue<float, string> Timer ids by due time; cancelled ids are skipped. */
    private SplPriorityQueue $timerQueue;

    /** @var array<string, array{stream: resource, callback: Closure(string, resource): void}> */
    private array $readWatchers = [];

    /** @var array<string, array{stream: resource, callback: Closure(string, resource): void}> */
    private array $writeWatchers = [];

    /** @var WeakMap<Fiber<mixed, mixed, mixed, mixed>, true> Fibers created by async(), which may suspend. */
    private WeakMap $fibers;

    private int $nextId = 0;
    private bool $ticking = false;

    public function __construct()
    {
        $this->timerQueue = new SplPriorityQueue();
        $this->fibers = new WeakMap();
    }

    /**
     * Returns the loop of the current PHP thread.
     */
    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Runs the callback on the next tick.
     *
     * @param Closure(string): void $callback Receives the callback id.
     */
    public function defer(Closure $callback): string
    {
        $id = $this->newId();
        $this->deferred[$id] = $callback;

        return $id;
    }

    /**
     * Runs the callback once, after the given number of seconds.
     *
     * @param Closure(string): void $callback Receives the timer id.
     */
    public function delay(float $seconds, Closure $callback): string
    {
        $id = $this->newId();
        $at = hrtime(true) / 1e9 + max(0.0, $seconds);

        $this->timers[$id] = ['at' => $at, 'callback' => $callback];
        $this->timerQueue->insert($id, -$at);

        return $id;
    }

    /**
     * Runs the callback every time the stream becomes readable, until cancelled.
     *
     * @param resource $stream
     * @param Closure(string, resource): void $callback Receives the watcher id and the stream.
     */
    public function onReadable(mixed $stream, Closure $callback): string
    {
        $id = $this->newId();
        $this->readWatchers[$id] = ['stream' => $stream, 'callback' => $callback];

        return $id;
    }

    /**
     * Runs the callback every time the stream becomes writable, until cancelled.
     *
     * @param resource $stream
     * @param Closure(string, resource): void $callback Receives the watcher id and the stream.
     */
    public function onWritable(mixed $stream, Closure $callback): string
    {
        $id = $this->newId();
        $this->writeWatchers[$id] = ['stream' => $stream, 'callback' => $callback];

        return $id;
    }

    /**
     * Cancels a deferred callback, timer or stream watcher. Unknown ids are ignored.
     */
    public function cancel(string $id): void
    {
        unset($this->deferred[$id], $this->timers[$id], $this->readWatchers[$id], $this->writeWatchers[$id]);
    }

    /**
     * Runs the loop until there is no pending callback, timer or watcher.
     */
    public function run(): void
    {
        while ($this->tick()) {
            // Keep ticking until the loop is idle.
        }
    }

    /**
     * Runs one iteration: deferred callbacks, due timers and ready streams.
     *
     * @return bool false when there was nothing left to run.
     */
    public function tick(): bool
    {
        if ($this->ticking) {
            throw new LogicException('Cannot block inside an event loop callback; run the code with Async::run() instead.');
        }

        if (!$this->hasPendingWork()) {
            return false;
        }

        $this->ticking = true;

        try {
            $this->runDeferred();
            $this->waitForStreams($this->deferred === [] ? $this->nextTimerTimeout() : 0.0);
            $this->runDueTimers();
        } finally {
            $this->ticking = false;
        }

        return true;
    }

    /**
     * Whether the caller runs inside an Async::run() task, where waiting suspends
     * the task instead of blocking the thread.
     */
    public function inAsyncTask(): bool
    {
        $fiber = Fiber::getCurrent();

        return $fiber !== null && isset($this->fibers[$fiber]);
    }

    /**
     * Creates the Suspension for the current execution context.
     */
    public function getSuspension(): Suspension
    {
        $fiber = Fiber::getCurrent();

        if ($fiber !== null && !isset($this->fibers[$fiber])) {
            $fiber = null;
        }

        return new Suspension($this, $fiber);
    }

    /**
     * Starts the task in a Fiber on the next tick and returns its Future.
     *
     * @template T
     * @param Closure(): T $task
     * @return Future<T>
     */
    public function async(Closure $task): Future
    {
        /** @var Future<T> $future */
        $future = new Future($this);

        $fiber = new Fiber(static function () use ($task, $future): void {
            try {
                $future->complete($task());
            } catch (\Throwable $e) {
                $future->fail($e);
            }
        });

        $this->fibers[$fiber] = true;
        $this->defer(static function () use ($fiber): void {
            $fiber->start();
        });

        return $future;
    }

    public function hasPendingWork(): bool
    {
        return $this->deferred !== []
            || $this->timers !== []
            || $this->readWatchers !== []
            || $this->writeWatchers !== [];
    }

    /**
     * Drops every pending callback, timer and watcher. Suspended Fibers lose
     * their last reference and are destroyed, which runs their finally blocks.
     */
    public function reset(): void
    {
        $this->deferred = [];
        $this->timers = [];
        $this->timerQueue = new SplPriorityQueue();
        $this->readWatchers = [];
        $this->writeWatchers = [];
        $this->fibers = new WeakMap();
        $this->ticking = false;
    }

    private function newId(): string
    {
        return (string) $this->nextId++;
    }

    private function runDeferred(): void
    {
        $deferred = $this->deferred;
        $this->deferred = [];

        foreach ($deferred as $id => $callback) {
            $callback((string) $id);
        }
    }

    /**
     * Seconds until the next timer, 0 when one is due, or null without timers.
     */
    private function nextTimerTimeout(): ?float
    {
        while (!$this->timerQueue->isEmpty()) {
            /** @var string $id */
            $id = $this->timerQueue->top();

            if (!isset($this->timers[$id])) {
                $this->timerQueue->extract();
                continue;
            }

            return max(0.0, $this->timers[$id]['at'] - hrtime(true) / 1e9);
        }

        return null;
    }

    private function runDueTimers(): void
    {
        $now = hrtime(true) / 1e9;

        while (!$this->timerQueue->isEmpty()) {
            /** @var string $id */
            $id = $this->timerQueue->top();
            $timer = $this->timers[$id] ?? null;

            if ($timer === null) {
                $this->timerQueue->extract();
                continue;
            }

            if ($timer['at'] > $now) {
                return;
            }

            $this->timerQueue->extract();
            unset($this->timers[$id]);
            $timer['callback']($id);
        }
    }

    /**
     * Waits up to $timeout seconds (null = until a stream is ready) and runs
     * the callbacks of the ready streams.
     */
    private function waitForStreams(?float $timeout): void
    {
        if ($this->readWatchers === [] && $this->writeWatchers === []) {
            if ($timeout !== null && $timeout > 0) {
                usleep((int) ($timeout * 1e6));
            }

            return;
        }

        $read = array_column($this->readWatchers, 'stream');
        $write = array_column($this->writeWatchers, 'stream');
        $except = null;

        $seconds = $timeout === null ? null : (int) $timeout;
        $microseconds = $timeout === null ? null : (int) (($timeout - (int) $timeout) * 1e6);

        $ready = @stream_select($read, $write, $except, $seconds, $microseconds);

        if ($ready === false || $ready === 0) {
            return;
        }

        $this->dispatchReady($this->readWatchers, $read);
        $this->dispatchReady($this->writeWatchers, $write);
    }

    /**
     * @param array<string, array{stream: resource, callback: Closure(string, resource): void}> $watchers
     * @param array<resource> $ready
     */
    private function dispatchReady(array $watchers, array $ready): void
    {
        if ($ready === []) {
            return;
        }

        $readyIds = [];
        foreach ($ready as $stream) {
            $readyIds[get_resource_id($stream)] = true;
        }

        foreach ($watchers as $id => $watcher) {
            $id = (string) $id;

            if (!isset($readyIds[get_resource_id($watcher['stream'])])) {
                continue;
            }

            // A previous callback may have cancelled this watcher.
            if (!isset($this->readWatchers[$id]) && !isset($this->writeWatchers[$id])) {
                continue;
            }

            $watcher['callback']($id, $watcher['stream']);
        }
    }
}
