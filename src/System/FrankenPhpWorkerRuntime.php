<?php

declare(strict_types=1);

namespace Atria\System;

use Atria\System\Contracts\WorkerRuntime;

final class FrankenPhpWorkerRuntime implements WorkerRuntime
{
    public function handle(callable $handler): bool
    {
        if (!function_exists('frankenphp_handle_request')) {
            throw new \RuntimeException('FrankenPHP worker runtime is not available.');
        }

        return frankenphp_handle_request($handler);
    }
}
