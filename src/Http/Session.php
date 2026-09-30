<?php

declare(strict_types=1);

namespace Atria\Http;

/**
 * Starts the PHP session only when something reads or writes it.
 *
 * Requests that never touch the session create no session file, send no
 * cookie and take no session lock. Reads never create a session for a
 * visitor without the session cookie, and load an existing one with
 * `read_and_close`, releasing its lock at once, so requests of the same user
 * that only read do not queue behind each other. Writes open the session
 * normally, with its lock, until the end of the request.
 */
final class Session
{
    /**
     * Whether $_SESSION holds data loaded read-only during this request.
     * Static because $_SESSION is global and App closes the session through
     * its own instance.
     */
    private static bool $loadedReadOnly = false;

    /**
     * Opens the session for writing, taking its lock until close().
     */
    public function start(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            // Re-reads the stored data, so writes apply over the latest state.
            session_start();
            self::$loadedReadOnly = false;
        }
    }

    public function isStarted(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->load()) {
            return $default;
        }

        return $_SESSION[$key] ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        $this->start();

        $_SESSION[$key] = $value;
    }

    /**
     * Returns the value and removes it, e.g. for flash messages. Only opens
     * the session for writing when the key exists.
     */
    public function pull(string $key, mixed $default = null): mixed
    {
        if (!$this->load() || !array_key_exists($key, $_SESSION)) {
            return $default;
        }

        $this->start();

        $value = $_SESSION[$key] ?? $default;
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
        self::$loadedReadOnly = false;
    }

    /**
     * Makes the session data available for reading without holding its lock.
     *
     * @return bool false when the visitor has no session to read.
     */
    private function load(): bool
    {
        if (session_status() === PHP_SESSION_ACTIVE || self::$loadedReadOnly) {
            return true;
        }

        if (!$this->hasSessionCookie()) {
            return false;
        }

        session_start(['read_and_close' => true]);
        self::$loadedReadOnly = true;

        return true;
    }

    /**
     * Without cookie-based sessions the id may come from elsewhere, so only a
     * missing cookie with cookies enabled proves there is no session.
     */
    private function hasSessionCookie(): bool
    {
        if (!filter_var(ini_get('session.use_cookies'), FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }

        $id = $_COOKIE[session_name()] ?? null;

        return is_string($id) && $id !== '';
    }
}
