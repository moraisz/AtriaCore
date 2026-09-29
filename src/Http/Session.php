<?php

declare(strict_types=1);

namespace Atria\Http;

/**
 * Starts the PHP session only when something reads or writes it.
 *
 * Requests that never touch the session create no session file, send no
 * cookie and take no session lock, so they no longer queue behind other
 * requests of the same user.
 */
final class Session
{
    public function start(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    public function isStarted(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->start();

        return $_SESSION[$key] ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        $this->start();

        $_SESSION[$key] = $value;
    }

    /**
     * Returns the value and removes it, e.g. for flash messages.
     */
    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        unset($_SESSION[$key]);

        return $value;
    }

    public function forget(string $key): void
    {
        $this->start();

        unset($_SESSION[$key]);
    }

    /**
     * Writes and releases the session, then clears $_SESSION so the next
     * request of this worker cannot read it without starting its own session.
     */
    public function close(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $_SESSION = [];
    }
}
