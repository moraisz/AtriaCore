<?php

declare(strict_types=1);

namespace Atria\Modules\Auth\Exceptions;

final class InvalidCredentialsException extends AuthenticationException
{
    public function __construct(string $message = 'Invalid credentials.')
    {
        parent::__construct($message);
    }
}
