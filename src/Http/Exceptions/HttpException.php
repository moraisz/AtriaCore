<?php

declare(strict_types=1);

namespace Atria\Http\Exceptions;

class HttpException extends \InvalidArgumentException
{
    public function __construct(string $message, private int $statusCode)
    {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
