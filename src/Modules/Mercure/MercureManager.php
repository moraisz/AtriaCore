<?php

declare(strict_types=1);

namespace Atria\Modules\Mercure;

use Atria\Modules\Auth\JWT;
use Atria\Http\Request;

final class MercureManager
{
    public function __construct(
        private readonly MercureConfig $config,
    ) {}

    public function hubUrl(): string
    {
        return $this->config->requireHubUrl();
    }

    /**
     * @param array<int, string>|string $topics
     */
    public function subscribeUrl(array|string $topics): string
    {
        $hubUrl = $this->hubUrl();
        $query = $this->buildTopicQuery(is_array($topics) ? $topics : [$topics]);

        return $hubUrl . (str_contains($hubUrl, '?') ? '&' : '?') . $query;
    }

    public function discoveryLink(): string
    {
        return '<' . $this->hubUrl() . '>; rel="mercure"';
    }

    public function subscribeJwtTtl(): int
    {
        $this->config->assertEnabled();

        return $this->config->subscribeJwtTtl;
    }

    /**
     * @param array<int, string>|string $topics
     */
    public function subscribeToken(array|string $topics, ?int $ttl = null): string
    {
        $this->config->assertEnabled();
        $ttl ??= $this->subscribeJwtTtl();

        return JWT::encode([
            'mercure' => [
                'subscribe' => is_array($topics) ? $topics : [$topics],
            ],
            'exp' => time() + $ttl,
        ], $this->config->requireSubscribeJwtKey());
    }

    /**
     * @param array<int, string>|string $topics
     */
    public function authorizationToken(array|string $topics, ?int $ttl = null): string
    {
        return $this->subscribeToken($topics, $ttl);
    }

    public function hubPath(): string
    {
        $path = parse_url($this->hubUrl(), PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/';
    }

    public function authorizationCookieDomain(): string
    {
        $this->config->assertEnabled();

        return $this->config->authorizationCookieDomain;
    }

    public function shouldUseSecureCookie(?Request $request = null): bool
    {
        $scheme = parse_url($this->hubUrl(), PHP_URL_SCHEME);

        if (!is_string($scheme) || $scheme === '') {
            return $request?->isSecure() ?? false;
        }

        return strtolower($scheme) === 'https';
    }

    /**
     * @param array<int, string> $topics
     */
    private function buildTopicQuery(array $topics): string
    {
        return implode('&', array_map(
            static fn(string $topic): string => 'topic=' . rawurlencode($topic),
            $topics,
        ));
    }

}
