<?php

declare(strict_types=1);

namespace Atria\Stubs;

if (!function_exists('frankenphp_handle_request')) {
    /**
     * Handle a FrankenPHP request
     * @param callable $callback
     * @return bool
     */
    function frankenphp_handle_request(callable $callback): bool
    {
        return true;
    }
}

if (!function_exists('headers_send')) {
    /**
     * Send HTTP headers with a specific status code
     * @param int $code
     * @return void
     */
    function headers_send(int $code): void {}

}

if (!function_exists('frankenphp_log')) {
    /**
     * Log a message via FrankenPHP
     * @param string $message
     * @param int    $level
     * @param array<int, mixed> $context
     * @return void
     */
    function frankenphp_log(
        string $message,
        int $level = FRANKENPHP_LOG_LEVEL_INFO,
        array $context = [],
    ): void {}
}

if (!function_exists('mercure_publish')) {
    /**
     * Publish updates to the built-in Mercure hub
     * @param string|string[] $topics
     */
    function mercure_publish(
        string|array $topics,
        string $data = '',
        bool $private = false,
        ?string $id = null,
        ?string $type = null,
        ?int $retry = null,
    ): string {
        return '';
    }
}
