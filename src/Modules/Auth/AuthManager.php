<?php

declare(strict_types=1);

namespace Atria\Modules\Auth;

use Closure;
use Atria\Modules\Auth\Exceptions\InvalidAccessTokenException;
use Atria\Modules\Auth\Exceptions\InvalidCredentialsException;
use Atria\Modules\Auth\Exceptions\InvalidRefreshTokenException;
use Atria\Modules\Auth\Exceptions\RefreshTokenExpiredException;
use Atria\Modules\Auth\Services\AuthTokenService;
use Atria\Modules\Auth\Data\AuthenticatedPrincipal;
use Atria\Http\Response;
use Atria\Database\Contracts\QueryBuilder;

final class AuthManager
{
    /** @param Closure(): QueryBuilder $queryBuilderResolver */
    public function __construct(
        private Closure $queryBuilderResolver,
        private AuthTokenService $tokenService,
        private AuthConfig $config,
    ) {}

    /**
     * @return array{user: AuthenticatedPrincipal, access_token: string, refresh_token: string, refresh_token_hash: string, access_expires_at: int, refresh_expires_at: int}
     */
    public function attempt(string $email, string $password, string $deviceInfo = 'unknown'): array
    {
        $row = $this->queryBuilder()
            ->select(['id', 'name', 'email', 'password_hash'])
            ->from($this->config->usersTable)
            ->where('email', '=', $email)
            ->first();

        $storedPassword = is_string($row['password_hash'] ?? null) ? $row['password_hash'] : '';

        if ($row === null || $storedPassword === '' || !password_verify($password, $storedPassword)) {
            throw new InvalidCredentialsException();
        }

        $user = $this->authenticatedUserFromRow($row);
        $tokens = $this->issuePairForUser($user, $deviceInfo);

        return ['user' => $user] + $tokens;
    }

    /**
     * @return array{access_token: string, refresh_token: string, refresh_token_hash: string, access_expires_at: int, refresh_expires_at: int}
     */
    public function issuePairForUser(AuthenticatedPrincipal $user, string $deviceInfo = 'unknown', ?int $refreshExpiresAt = null): array
    {
        $tokens = $this->tokenService->issuePair($user->id, $user->email, $refreshExpiresAt);

        $this->queryBuilder()
            ->insertInto($this->config->refreshTokensTable, [
                'user_id',
                'token_hash',
                'device_info',
                'expires_at',
            ])
            ->values([
                $user->id,
                $tokens['refresh_token_hash'],
                $deviceInfo,
                $this->databaseTimestamp($tokens['refresh_expires_at']),
            ])
            ->execute();

        return $tokens;
    }

    /**
     * @return array{access_token: string, refresh_token: string, refresh_token_hash: string, access_expires_at: int, refresh_expires_at: int}
     */
    public function refresh(string $refreshToken, string $deviceInfo = 'unknown'): array
    {
        try {
            $payload = $this->tokenService->decodeRefreshToken($refreshToken);
        } catch (\InvalidArgumentException) {
            throw new InvalidRefreshTokenException('Invalid refresh token');
        }

        $exp = $payload['exp'] ?? null;
        $userId = $payload['sub'] ?? null;
        $jti = $payload['jti'] ?? null;

        if (!is_int($exp) || $exp <= time()) {
            throw new RefreshTokenExpiredException();
        }

        if (!is_int($userId) || !is_string($jti) || $jti === '') {
            throw new InvalidRefreshTokenException('Invalid refresh token');
        }

        $email = is_string($payload['email'] ?? null) ? $payload['email'] : '';
        $tokens = $this->tokenService->issuePair($userId, $email, $exp);
        $now = $this->databaseTimestamp(time());
        $rotated = $this->queryBuilder()->statement(
            <<<SQL
                WITH consumed AS (
                    UPDATE {$this->config->refreshTokensTable}
                    SET revoked_at = ?
                    WHERE user_id = ?
                      AND token_hash = ?
                      AND revoked_at IS NULL
                      AND expires_at > ?
                    RETURNING user_id
                )
                INSERT INTO {$this->config->refreshTokensTable} (user_id, token_hash, device_info, expires_at)
                SELECT user_id, ?, ?, ?
                FROM consumed
                RETURNING user_id
                SQL,
            [
                $now,
                $userId,
                hash('sha256', $jti),
                $now,
                $tokens['refresh_token_hash'],
                $deviceInfo,
                $this->databaseTimestamp($exp),
            ],
        );

        if ($rotated === []) {
            throw new InvalidRefreshTokenException('Refresh token not found or revoked');
        }

        return $tokens;
    }

    public function logout(?string $refreshToken): void
    {
        if ($refreshToken === null) {
            return;
        }

        try {
            $payload = $this->tokenService->decodeRefreshToken($refreshToken);
        } catch (\InvalidArgumentException) {
            return;
        }

        $jti = $payload['jti'] ?? null;
        $userId = $payload['sub'] ?? null;
        $expiresAt = $payload['exp'] ?? null;

        if (!is_int($expiresAt) || $expiresAt <= time() || !is_int($userId) || !is_string($jti) || $jti === '') {
            return;
        }

        $this->queryBuilder()
            ->update($this->config->refreshTokensTable)
            ->set(['revoked_at' => $this->databaseTimestamp(time())])
            ->where('user_id', '=', $userId)
            ->where('token_hash', '=', hash('sha256', $jti))
            ->whereNull('revoked_at')
            ->execute();
    }

    /**
     * @return array<string, mixed>
     */
    public function decodeAccessToken(string $accessToken): array
    {
        try {
            return $this->tokenService->decodeAccessToken($accessToken);
        } catch (\InvalidArgumentException) {
            throw new InvalidAccessTokenException();
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function authenticatedUserFromPayload(array $payload): AuthenticatedPrincipal
    {
        $userId = $payload['sub'] ?? null;
        $email = $payload['email'] ?? null;
        $name = $payload['name'] ?? null;

        if (!is_int($userId) || $userId <= 0 || !is_string($email) || $email === '') {
            throw new InvalidAccessTokenException();
        }

        return new AuthenticatedPrincipal(
            $userId,
            $email,
            is_string($name) ? $name : null,
        );
    }

    /**
     * @param array{access_token: string, refresh_token: string, access_expires_at: int, refresh_expires_at: int} $tokens
     */
    public function attachCookies(Response $response, array $tokens): void
    {
        $accessToken = $tokens['access_token'];
        $accessExpiresAt = $tokens['access_expires_at'];
        $refreshToken = $tokens['refresh_token'];
        $refreshExpiresAt = $tokens['refresh_expires_at'];

        $response
            ->setCookie(
                $this->config->accessCookie,
                $accessToken,
                $accessExpiresAt,
                secure: $this->config->cookieSecure,
                sameSite: $this->config->cookieSameSite,
            )
            ->setCookie(
                $this->config->refreshCookie,
                $refreshToken,
                $refreshExpiresAt,
                secure: $this->config->cookieSecure,
                sameSite: $this->config->cookieSameSite,
            );
    }

    public function clearCookies(Response $response): void
    {
        $expired = time() - 3600;

        $response
            ->setCookie(
                $this->config->accessCookie,
                '',
                $expired,
                secure: $this->config->cookieSecure,
                sameSite: $this->config->cookieSameSite,
            )
            ->setCookie(
                $this->config->refreshCookie,
                '',
                $expired,
                secure: $this->config->cookieSecure,
                sameSite: $this->config->cookieSameSite,
            );
    }

    public function accessCookieName(): string
    {
        return $this->config->accessCookie;
    }

    public function refreshCookieName(): string
    {
        return $this->config->refreshCookie;
    }

    public function redirectGuestTo(): string
    {
        return $this->config->redirectGuestTo;
    }

    public function redirectAuthenticatedTo(): string
    {
        return $this->config->redirectAuthenticatedTo;
    }

    private function queryBuilder(): QueryBuilder
    {
        return ($this->queryBuilderResolver)();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function authenticatedUserFromRow(array $row): AuthenticatedPrincipal
    {
        $userId = $row['id'] ?? null;
        $email = $row['email'] ?? null;
        $name = $row['name'] ?? null;

        if (!is_numeric($userId) || !is_string($email) || $email === '') {
            throw new InvalidCredentialsException();
        }

        return new AuthenticatedPrincipal(
            (int) $userId,
            $email,
            is_string($name) ? $name : null,
        );
    }

    private function databaseTimestamp(int $unixTimestamp): string
    {
        return date('Y-m-d H:i:s', $unixTimestamp);
    }
}
