<?php

declare(strict_types=1);

namespace Atria\Async;

use LogicException;
use Throwable;

/**
 * Result of an Async::run() task.
 *
 * @template T
 */
final class Future
{
    private bool $complete = false;

    /** @var T|null */
    private mixed $value = null;

    private ?Throwable $error = null;

    /** @var list<Suspension> */
    private array $waiters = [];

    public function __construct(private readonly EventLoop $loop) {}

    public function isComplete(): bool
    {
        return $this->complete;
    }

    /**
     * Waits for the task and returns its value, or rethrows its exception.
     *
     * @return T
     */
    public function await(): mixed
    {
        if (!$this->complete) {
            $suspension = $this->loop->getSuspension();
            $this->waiters[] = $suspension;
            $suspension->suspend();
        }

        if ($this->error !== null) {
            throw $this->error;
        }

        /** @var T */
        return $this->value;
    }

    /**
     * @internal Called by EventLoop::async() when the task returns.
     * @param T $value
     */
    public function complete(mixed $value): void
    {
        $this->settle($value, null);
    }

    /**
     * @internal Called by EventLoop::async() when the task throws.
     */
    public function fail(Throwable $error): void
    {
        $this->settle(null, $error);
    }

    private function settle(mixed $value, ?Throwable $error): void
    {
        if ($this->complete) {
            throw new LogicException('Future already completed.');
        }

        $this->complete = true;
        $this->value = $value;
        $this->error = $error;

        $waiters = $this->waiters;
        $this->waiters = [];

        foreach ($waiters as $waiter) {
            $waiter->resume();
        }
    }
}
