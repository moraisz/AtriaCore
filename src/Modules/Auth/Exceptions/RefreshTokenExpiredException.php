<?php

declare(strict_types=1);

namespace Atria\Modules\Auth\Exceptions;

final class RefreshTokenExpiredException extends AuthenticationException
{
    public function __construct(string $message = 'Refresh token expired')
    {
        parent::__construct($message);
    }
}
