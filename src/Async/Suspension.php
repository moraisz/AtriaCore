<?php

declare(strict_types=1);

namespace Atria\Async;

use Fiber;
use LogicException;
use Throwable;

/**
 * Waits for a single value in the current execution context.
 *
 * Inside an Async::run() Fiber, suspend() suspends the Fiber until resume() or
 * throw() schedules it back. Anywhere else it runs the event loop until the
 * value arrives, which makes the caller block like synchronous code.
 */
final class Suspension
{
    private bool $pending = true;
    private bool $suspended = false;
    private mixed $value = null;
    private ?Throwable $error = null;

    /**
     * @param Fiber<mixed, mixed, mixed, mixed>|null $fiber
     */
    public function __construct(
        private readonly EventLoop $loop,
        private readonly ?Fiber $fiber,
    ) {}

    public function suspend(): mixed
    {
        if ($this->suspended) {
            throw new LogicException('Suspension already awaited.');
        }

        $this->suspended = true;

        if ($this->fiber !== null) {
            if ($this->pending) {
                Fiber::suspend();
            }
        } else {
            while ($this->pending) {
                if (!$this->loop->tick()) {
                    throw new LogicException('Event loop stopped before the awaited value was ready.');
                }
            }
        }

        if ($this->error !== null) {
            throw $this->error;
        }

        return $this->value;
    }

    public function resume(mixed $value = null): void
    {
        $this->settle($value, null);
    }

    public function throw(Throwable $error): void
    {
        $this->settle(null, $error);
    }

    private function settle(mixed $value, ?Throwable $error): void
    {
        if (!$this->pending) {
            throw new LogicException('Suspension already resumed.');
        }

        $this->pending = false;
        $this->value = $value;
        $this->error = $error;

        $fiber = $this->fiber;

        if ($fiber !== null && $this->suspended) {
            $this->loop->defer(static function () use ($fiber): void {
                $fiber->resume();
            });
        }
    }
}
