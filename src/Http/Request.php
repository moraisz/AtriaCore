<?php

declare(strict_types=1);

namespace Atria\Http;

class Request
{
    /** @var array<string, string> */
    private array $params = [];

    /** @var array<string, mixed> */
    private array $query = [];

    /** @var array<string, mixed> */
    private array $body = [];

    /** @var array<string, mixed> */
    private array $server = [];

    /** @var array<string, string> */
    private array $cookies = [];

    /** @var array<string, mixed> */
    private array $attributes = [];

    private string $method = '';
    private string $path = '';

    public static function createFromGlobals(bool $earlyHints = false): self
    {
        $request = new self();

        /** @var array<string, mixed> $_SERVER */
        $request->server = $_SERVER;

        /** @var array<string, mixed> $_GET */
        $request->query = $_GET;

        /** @var array<string, string> $_COOKIE */
        $request->cookies = $_COOKIE;

        $serverReqMethod = $_SERVER['REQUEST_METHOD'] ?? null;
        $request->method = is_string($serverReqMethod) ? $serverReqMethod : 'GET';

        $serverReqUri = $_SERVER['REQUEST_URI'] ?? null;
        $requestUri = is_string($serverReqUri) ? $serverReqUri : '/';
        $parsedUrl = parse_url($requestUri, PHP_URL_PATH);
        $request->path = is_string($parsedUrl) ? $parsedUrl : '/';

        $serverContentType = $_SERVER['CONTENT_TYPE'] ?? null;
        $contentType = is_string($serverContentType) ? $serverContentType : '';

        if (str_contains($contentType, 'application/json')) {
            $json = file_get_contents('php://input');
            $decoded = $json !== false ? json_decode($json, true) : null;

            /** @var array<string, mixed> $body */
            $body = is_array($decoded) ? $decoded : [];

            $request->body = $body;
        } elseif ($request->method === 'POST') {
            /** @var array<string, mixed> $body */
            $body = $_POST;

            $request->body = $body;
        } else {
            $json = file_get_contents('php://input');
            $decoded = $json !== false ? json_decode($json, true) : null;

            /** @var array<string, mixed> $body */
            $body = is_array($decoded) ? $decoded : [];

            $request->body = $body;
        }

        if ($earlyHints && !$request->isJson()) {
            $request->startEarlyHints();
        }

        return $request;
    }
    /**
     * @param array<string, string> $params
     */
    public function setParams(array $params): void
    {
        $this->params = $params;
    }

    /**
     * @return array<string, string>
     */
    public function getParams(): array
    {
        return $this->params;
    }

    public function getParam(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function getQuery(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->query;
        }
        return $this->query[$key] ?? $default;
    }

    public function getBody(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->body;
        }
        return $this->body[$key] ?? $default;
    }

    public function bodyString(string $key, string $default = ''): string
    {
        $value = $this->getBody($key, $default);

        return is_string($value) ? $value : $default;
    }

    public function bodyOptionalString(string $key): ?string
    {
        $value = trim($this->bodyString($key));

        return $value !== '' ? $value : null;
    }

    public function bodyBool(string $key, bool $default = false): bool
    {
        $value = $this->getBody($key, $default);

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    public function bodyInt(string $key, ?int $default = null, ?int $min = null): ?int
    {
        $value = $this->getBody($key);

        if ($value === null || $value === '') {
            return $default;
        }

        if (is_int($value)) {
            $int = $value;
        } elseif (is_string($value)) {
            $int = filter_var($value, FILTER_VALIDATE_INT);

            if ($int === false) {
                return null;
            }
        } else {
            return null;
        }

        if ($min !== null && $int < $min) {
            return null;
        }

        return $int;
    }

    /**
     * @return array<int, string>
     */
    public function bodyStringList(string $key): array
    {
        $value = $this->getBody($key);
        $items = is_array($value) ? $value : [$value];

        return array_values(array_filter(array_map(
            static fn(mixed $item): string => is_string($item) ? trim($item) : '',
            $items,
        ), static fn(string $item): bool => $item !== ''));
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getHeader(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $this->server[$key] ?? null;
        return is_string($value) ? $value : null;
    }

    public function getCookie(string $name, ?string $default = null): ?string
    {
        $value = $this->cookies[$name] ?? null;
        return is_string($value) ? $value : $default;
    }

    public function isSecure(): bool
    {
        $https = $this->server['HTTPS'] ?? null;

        if ($https === '1') {
            return true;
        }

        if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
            return true;
        }

        $requestScheme = $this->server['REQUEST_SCHEME'] ?? null;

        if (is_string($requestScheme) && strtolower($requestScheme) === 'https') {
            return true;
        }

        $forwardedProto = $this->server['HTTP_X_FORWARDED_PROTO'] ?? null;

        if (is_string($forwardedProto) && strtolower(trim(explode(',', $forwardedProto)[0])) === 'https') {
            return true;
        }

        $serverPort = $this->server['SERVER_PORT'] ?? null;

        return $serverPort === '443' || $serverPort === 443;
    }

    public function isJson(): bool
    {
        return str_contains($this->getHeader('Content-Type') ?? '', 'application/json');
    }

    public function startEarlyHints(): void
    {
        header('Link: <assets/css/style.css>; rel=preload; as=style', false, 103);
        header('Link: <assets/js/app.js>; rel=preload; as=script', false, 103);
        headers_send(103);
    }
}
