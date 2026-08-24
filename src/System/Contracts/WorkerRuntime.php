<?php

declare(strict_types=1);

namespace Atria\System\Contracts;

interface WorkerRuntime
{
    /** @param callable(): void $handler */
    public function handle(callable $handler): bool;
}
