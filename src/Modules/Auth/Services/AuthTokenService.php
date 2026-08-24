<?php

declare(strict_types=1);

namespace Atria\Modules\Auth\Services;

use Atria\Modules\Auth\JWT;

class AuthTokenService
{
    public function __construct(
        private string $secret,
        private int $accessTtl = 300,
        private int $refreshTtl = 86400,
    ) {}

    /**
     * @return array{access_token: string, refresh_token: string, refresh_token_hash: string, access_expires_at: int, refresh_expires_at: int}
     */
    public function issuePair(int $userId, string $email, ?int $refreshExpiresAt = null): array
    {
        $accessExpiresAt = time() + $this->accessTtl;
        $refreshExpiresAt ??= time() + $this->refreshTtl;

        $accessToken = JWT::encode([
            'sub' => $userId,
            'email' => $email,
            'exp' => $accessExpiresAt,
            'type' => 'access',
        ], $this->secret);

        $jti = bin2hex(random_bytes(32));
        $refreshHash = hash('sha256', $jti);

        $refreshToken = JWT::encode([
            'sub' => $userId,
            'email' => $email,
            'exp' => $refreshExpiresAt,
            'type' => 'refresh',
            'jti' => $jti,
        ], $this->secret);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'refresh_token_hash' => $refreshHash,
            'access_expires_at' => $accessExpiresAt,
            'refresh_expires_at' => $refreshExpiresAt,
        ];
    }

    /** @return array<string, mixed> */
    public function decodeAccessToken(string $token): array
    {
        return $this->decode($token, 'access');
    }

    /** @return array<string, mixed> */
    public function decodeRefreshToken(string $token): array
    {
        return $this->decode($token, 'refresh');
    }

    /** @return array<string, mixed> */
    private function decode(string $token, string $type): array
    {
        try {
            $payload = JWT::decode($token, $this->secret);
        } catch (\Throwable) {
            throw new \InvalidArgumentException('Invalid token');
        }

        $sub = $payload['sub'] ?? null;
        $exp = $payload['exp'] ?? null;

        if (($payload['type'] ?? null) !== $type || !is_int($sub) || $sub <= 0 || !is_int($exp)) {
            throw new \InvalidArgumentException('Invalid token');
        }

        if ($type === 'refresh' && (!isset($payload['jti']) || !is_string($payload['jti']) || $payload['jti'] === '')) {
            throw new \InvalidArgumentException('Invalid token');
        }

        return $payload;
    }
}
