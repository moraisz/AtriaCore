<?php

declare(strict_types=1);

namespace Atria\Modules\Auth\Exceptions;

final class InvalidRefreshTokenException extends AuthenticationException
{
    public function __construct(string $message = 'Invalid refresh token')
    {
        parent::__construct($message);
    }
}
