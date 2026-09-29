<?php

declare(strict_types=1);

namespace Atria\Async;

use Closure;

/**
 * Entry point for concurrent work inside a request, on the thread's EventLoop.
 *
 * ```php
 * [$user, $orders] = Async::concurrently(
 *     fn() => User::findById($id),
 *     fn() => Order::forUser($id),
 * );
 * ```
 */
final class Async
{
    private function __construct() {}

    /**
     * Runs the task in a Fiber on the current thread's event loop.
     *
     * @template T
     * @param Closure(): T $task
     * @return Future<T>
     */
    public static function run(Closure $task): Future
    {
        return EventLoop::instance()->async($task);
    }

    /**
     * Runs the tasks concurrently and returns their results with the same keys.
     *
     * Every task runs to completion before this returns; when any of them throws,
     * the first exception (in task order) is rethrown after all have finished.
     *
     * @template T
     * @param Closure(): T ...$tasks
     * @return array<array-key, T>
     */
    public static function concurrently(Closure ...$tasks): array
    {
        $futures = array_map(self::run(...), $tasks);

        $results = [];
        $error = null;

        foreach ($futures as $key => $future) {
            try {
                $results[$key] = $future->await();
            } catch (\Throwable $e) {
                $error ??= $e;
            }
        }

        if ($error !== null) {
            throw $error;
        }

        return $results;
    }
}
