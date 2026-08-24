<?php

declare(strict_types=1);

namespace Atria\Modules\Auth;

final class AuthConfig
{
    public function __construct(
        public string $driver,
        public string $secret,
        public int $accessTtl,
        public int $refreshTtl,
        public string $accessCookie,
        public string $refreshCookie,
        public bool $cookieSecure,
        public string $cookieSameSite,
        public string $redirectGuestTo,
        public string $redirectAuthenticatedTo,
        public string $usersTable,
        public string $refreshTokensTable,
    ) {
        if (!in_array($this->driver, ['standard', 'custom', 'off'], true)) {
            throw new \InvalidArgumentException('Auth driver must be standard, custom, or off.');
        }

        if ($this->driver !== 'off' && $this->secret === '') {
            throw new \RuntimeException('Auth secret is required when auth is enabled.');
        }

        if ($this->accessTtl < 1) {
            throw new \InvalidArgumentException('Auth access TTL must be greater than zero.');
        }

        if ($this->refreshTtl < 1) {
            throw new \InvalidArgumentException('Auth refresh TTL must be greater than zero.');
        }

        if (!in_array($this->cookieSameSite, ['Lax', 'Strict', 'None'], true)) {
            throw new \InvalidArgumentException('Auth cookie same-site must be Lax, Strict, or None.');
        }

        if ($this->driver !== 'off' && ($this->usersTable === '' || $this->refreshTokensTable === '')) {
            throw new \InvalidArgumentException('Auth table names must be non-empty when auth is enabled.');
        }
    }

    public function isEnabled(): bool
    {
        return $this->driver !== 'off';
    }

    public function usesStandardMigrations(): bool
    {
        return $this->driver === 'standard';
    }
}
