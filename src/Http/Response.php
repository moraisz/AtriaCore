<?php

declare(strict_types=1);

namespace Atria\Http;

use Atria\Modules\Mercure\MercureManager;

class Response
{
    private int $statusCode = 200;
    /** @var array<string, string> */
    private array $headers = [];
    /** @var array<int, string> */
    private array $cookies = [];
    private string $content = '';
    private bool $sent = false;
    private ?Request $requestContext = null;
    private ?MercureManager $mercureManager = null;

    private const STATUS_TEXTS = [
        200 => 'OK',
        201 => 'Created',
        204 => 'No Content',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        422 => 'Unprocessable Entity',
        500 => 'Internal Server Error',
    ];

    public function setStatusCode(int $code): self
    {
        $this->statusCode = $code;
        return $this;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function setRequestContext(Request $request): self
    {
        $this->requestContext = $request;

        return $this;
    }

    public function setMercureManager(MercureManager $mercureManager): self
    {
        $this->mercureManager = $mercureManager;

        return $this;
    }

    public function setHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function appendHeader(string $name, string $value, string $separator = ', '): self
    {
        if (isset($this->headers[$name]) && $this->headers[$name] !== '') {
            $this->headers[$name] .= $separator . $value;

            return $this;
        }

        return $this->setHeader($name, $value);
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function setContent(string $content): self
    {
        $this->content = $content;
        return $this;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setCookie(
        string $name,
        string $value,
        int $expires = 0,
        string $path = '/',
        string $domain = '',
        bool $secure = false,
        bool $httpOnly = true,
        string $sameSite = 'Lax',
    ): self {
        $cookie = rawurlencode($name) . '=' . rawurlencode($value);

        if ($expires !== 0) {
            $cookie .= '; Expires=' . gmdate('D, d M Y H:i:s T', $expires);
        }

        if ($path !== '') {
            $cookie .= '; Path=' . $path;
        }

        if ($domain !== '') {
            $cookie .= '; Domain=' . $domain;
        }

        if ($secure) {
            $cookie .= '; Secure';
        }

        if ($httpOnly) {
            $cookie .= '; HttpOnly';
        }

        if ($sameSite !== '') {
            $cookie .= '; SameSite=' . $sameSite;
        }

        $this->cookies[] = $cookie;

        return $this;
    }

    /** @return array<int, string> */
    public function getCookies(): array
    {
        return $this->cookies;
    }

    /** @param array<mixed>|object|null $data */
    public function json(array|object|null $data, ?int $statusCode = null): self
    {
        if ($statusCode !== null) {
            $this->setStatusCode($statusCode);
        }

        $this->setHeader('Content-Type', 'application/json');
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->setContent($encoded !== false ? $encoded : '');

        return $this;
    }

    public function html(string $html, ?int $statusCode = null): self
    {
        if ($statusCode !== null) {
            $this->setStatusCode($statusCode);
        }

        $this->setHeader('Content-Type', 'text/html; charset=utf-8');
        $this->setContent($html);

        return $this;
    }

    public function redirect(string $url, ?int $statusCode = null): self
    {
        $this->setHeader('Location', $url);
        $this->setStatusCode($statusCode ?? 302);

        return $this;
    }

    public function text(string $text, ?int $statusCode = null): self
    {
        if ($statusCode !== null) {
            $this->setStatusCode($statusCode);
        }

        $this->setHeader('Content-Type', 'text/plain; charset=utf-8');
        $this->setContent($text);

        return $this;
    }

    public function mercure(): self
    {
        return $this->appendHeader('Link', $this->mercureManager()->discoveryLink());
    }

    /**
     * @param array<int, string>|string $topics
     */
    public function mercureAuthorization(array|string $topics, ?int $ttl = null): self
    {
        $mercureManager = $this->mercureManager();
        $cookieTtl = $ttl ?? $mercureManager->subscribeJwtTtl();

        return $this->setCookie(
            'mercureAuthorization',
            $mercureManager->subscribeToken($topics, $ttl),
            time() + $cookieTtl,
            $mercureManager->hubPath(),
            $mercureManager->authorizationCookieDomain(),
            secure: $mercureManager->shouldUseSecureCookie($this->requestContext),
            sameSite: 'Strict',
        );
    }

    private function mercureManager(): MercureManager
    {
        if ($this->mercureManager === null) {
            throw new \RuntimeException('Mercure manager is not configured.');
        }

        return $this->mercureManager;
    }

    public function send(): void
    {
        if ($this->sent) {
            return;
        }

        // send status code
        http_response_code($this->statusCode);

        // send headers
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}", true);
        }

        // send cookies
        foreach ($this->cookies as $cookie) {
            header("Set-Cookie: {$cookie}", false);
        }

        // send content
        echo $this->content;

        $this->sent = true;
    }

    public function isSent(): bool
    {
        return $this->sent;
    }

    public function getStatusText(): string
    {
        return self::STATUS_TEXTS[$this->statusCode] ?? 'Unknown';
    }
}
