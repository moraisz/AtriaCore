<?php

declare(strict_types=1);

namespace Atria\Modules\Auth\Exceptions;

use Atria\Http\Exceptions\HttpException;

class AuthenticationException extends HttpException
{
    public function __construct(string $message, int $statusCode = 401)
    {
        parent::__construct($message, $statusCode);
    }
}
