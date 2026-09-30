<?php

declare(strict_types=1);

namespace Atria\Modules\Csrf;

use Atria\Http\Session;

final class CsrfManager
{
    private const SESSION_KEY = 'csrf_token';
    private const TOKEN_BYTES = 32;

    private readonly Session $session;

    public function __construct(?Session $session = null)
    {
        $this->session = $session ?? new Session();
    }

    public function currentToken(): string
    {
        $token = $this->session->get(self::SESSION_KEY);

        if (!is_string($token) || $token === '') {
            return $this->rotateToken();
        }

        return $token;
    }

    public function rotateToken(): string
    {
        $token = bin2hex(random_bytes(self::TOKEN_BYTES));
        $this->session->put(self::SESSION_KEY, $token);

        return $token;
    }

    public function validateToken(?string $token): bool
    {
        $sessionToken = $this->session->get(self::SESSION_KEY);

        return is_string($token)
            && is_string($sessionToken)
            && $sessionToken !== ''
            && hash_equals($sessionToken, $token);
    }
}
