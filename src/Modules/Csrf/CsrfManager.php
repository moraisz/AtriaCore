<?php

declare(strict_types=1);

namespace Atria\Modules\Csrf;

final class CsrfManager
{
    private const SESSION_KEY = 'csrf_token';
    private const TOKEN_BYTES = 32;

    public function currentToken(): string
    {
        $token = $_SESSION[self::SESSION_KEY] ?? null;

        if (!is_string($token) || $token === '') {
            return $this->rotateToken();
        }

        return $token;
    }

    public function rotateToken(): string
    {
        $token = bin2hex(random_bytes(self::TOKEN_BYTES));
        $_SESSION[self::SESSION_KEY] = $token;

        return $token;
    }

    public function validateToken(?string $token): bool
    {
        $sessionToken = $_SESSION[self::SESSION_KEY] ?? null;

        return is_string($token)
            && is_string($sessionToken)
            && $sessionToken !== ''
            && hash_equals($sessionToken, $token);
    }
}
