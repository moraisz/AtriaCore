<?php

declare(strict_types=1);

namespace Atria\Database\Exceptions;

use RuntimeException;

/**
 * Database error raised by every driver, with the SQLSTATE of the failure.
 */
class QueryException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $sqlState = 'HY000',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function sqlState(): string
    {
        return $this->sqlState;
    }

    /**
     * SQLSTATE class 08 means the connection itself failed.
     */
    public function isConnectionError(): bool
    {
        return str_starts_with($this->sqlState, '08');
    }
}
