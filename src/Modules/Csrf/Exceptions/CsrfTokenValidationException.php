<?php

declare(strict_types=1);

namespace Atria\Modules\Csrf\Exceptions;

use Atria\Http\Exceptions\HttpException;

final class CsrfTokenValidationException extends HttpException
{
    public function __construct(string $message = 'CSRF token validation failed')
    {
        parent::__construct($message, 403);
    }
}
