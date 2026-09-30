<?php

declare(strict_types=1);

namespace Atria\Http\Client;

/**
 * Response received by HttpClient. Error statuses (4xx/5xx) are regular
 * responses; check ok() or status.
 */
final readonly class HttpResponse
{
    /**
     * @param array<string, list<string>> $headers Lowercase header names.
     */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
    ) {}

    /**
     * First value of the header, or null when absent.
     */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    /**
     * Whether the status is 2xx.
     */
    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * Decodes the body as JSON, throwing \JsonException when it is invalid.
     */
    public function json(): mixed
    {
        return json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
    }
}
