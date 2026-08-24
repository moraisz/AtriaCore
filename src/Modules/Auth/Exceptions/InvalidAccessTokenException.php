<?php

declare(strict_types=1);

namespace Atria\Modules\Auth\Exceptions;

final class InvalidAccessTokenException extends AuthenticationException
{
    public function __construct(string $message = 'Invalid access token')
    {
        parent::__construct($message);
    }
}
