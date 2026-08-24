<?php

declare(strict_types=1);

namespace Atria\Modules\Mercure;

use InvalidArgumentException;
use RuntimeException;

final class MercureConfig
{
    public function __construct(
        public readonly bool $enabled,
        public readonly string $hubUrl,
        public readonly string $subscribeJwtKey,
        public readonly int $subscribeJwtTtl,
        public readonly string $authorizationCookieDomain,
    ) {
        if ($this->subscribeJwtTtl < 1) {
            throw new InvalidArgumentException('Mercure subscribe JWT TTL must be greater than zero.');
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            self::boolValue($config, 'enabled'),
            self::stringValue($config, 'hub_url'),
            self::stringValue($config, 'subscribe_jwt_key'),
            self::intValue($config, 'subscribe_jwt_ttl'),
            trim(self::stringValue($config, 'authorization_cookie_domain')),
        );
    }

    public function assertEnabled(): void
    {
        if (!$this->enabled) {
            throw new RuntimeException('Mercure is disabled.');
        }
    }

    public function requireHubUrl(): string
    {
        $this->assertEnabled();

        if ($this->hubUrl === '') {
            throw new RuntimeException('Mercure hub URL is required.');
        }

        return $this->hubUrl;
    }

    public function requireSubscribeJwtKey(): string
    {
        $this->assertEnabled();

        if ($this->subscribeJwtKey === '') {
            throw new RuntimeException('Mercure subscribe JWT key is required.');
        }

        return $this->subscribeJwtKey;
    }

    /** @param array<string, mixed> $config */
    private static function boolValue(array $config, string $key): bool
    {
        if (!is_bool($config[$key] ?? null)) {
            throw new InvalidArgumentException("Mercure config {$key} must be a boolean.");
        }

        return $config[$key];
    }

    /** @param array<string, mixed> $config */
    private static function stringValue(array $config, string $key): string
    {
        if (!is_string($config[$key] ?? null)) {
            throw new InvalidArgumentException("Mercure config {$key} must be a string.");
        }

        return $config[$key];
    }

    /** @param array<string, mixed> $config */
    private static function intValue(array $config, string $key): int
    {
        if (!is_int($config[$key] ?? null)) {
            throw new InvalidArgumentException("Mercure config {$key} must be an integer.");
        }

        return $config[$key];
    }
}
