<?php

declare(strict_types=1);

namespace Atria\Http\Client;

use Atria\Async\EventLoop;
use Atria\Http\Client\Exceptions\HttpClientException;
use Atria\System\Contracts\Resettable;
use CurlHandle;
use InvalidArgumentException;
use RuntimeException;

/**
 * HTTP client on ext-curl.
 *
 * Outside Async::run() tasks a request blocks like any synchronous call.
 * Inside Async::concurrently() tasks requests overlap, so independent calls
 * take as long as the slowest one:
 *
 * ```php
 * [$user, $repos] = Async::concurrently(
 *     fn() => $http->get("https://api.example.com/users/{$id}")->json(),
 *     fn() => $http->get("https://api.example.com/users/{$id}/repos")->json(),
 * );
 * ```
 *
 * Connections and DNS results are reused across requests of the worker.
 * Only http and https URLs are accepted, including on redirects.
 *
 * @phpstan-type Options array{
 *     headers?: array<string, string>,
 *     query?: array<string, mixed>,
 *     json?: mixed,
 *     form?: array<string, mixed>,
 *     body?: string,
 *     timeout?: float,
 *     connect_timeout?: float,
 *     follow_redirects?: bool,
 * }
 */
final class HttpClient implements Resettable
{
    private const DEFAULT_TIMEOUT = 30.0;
    private const DEFAULT_CONNECT_TIMEOUT = 10.0;
    private const MAX_REDIRECTS = 5;

    private ?CurlDriver $driver = null;

    public function __construct(private readonly ?EventLoop $loop = null)
    {
        if (!extension_loaded('curl')) {
            throw new RuntimeException(self::class . ' requires the curl PHP extension.');
        }
    }

    /**
     * @param Options $options
     */
    public function get(string $url, array $options = []): HttpResponse
    {
        return $this->request('GET', $url, $options);
    }

    /**
     * @param Options $options
     */
    public function post(string $url, array $options = []): HttpResponse
    {
        return $this->request('POST', $url, $options);
    }

    /**
     * @param Options $options
     */
    public function put(string $url, array $options = []): HttpResponse
    {
        return $this->request('PUT', $url, $options);
    }

    /**
     * @param Options $options
     */
    public function patch(string $url, array $options = []): HttpResponse
    {
        return $this->request('PATCH', $url, $options);
    }

    /**
     * @param Options $options
     */
    public function delete(string $url, array $options = []): HttpResponse
    {
        return $this->request('DELETE', $url, $options);
    }

    /**
     * @param Options $options
     */
    public function request(string $method, string $url, array $options = []): HttpResponse
    {
        $handle = curl_init();
        /** @var array<string, list<string>> $headers */
        $headers = [];

        curl_setopt_array($handle, $this->curlOptions(strtoupper($method), $url, $options) + [
            CURLOPT_HEADERFUNCTION => static function (CurlHandle $handle, string $line) use (&$headers): int {
                self::collectHeader($headers, $line);

                return strlen($line);
            },
        ]);

        try {
            $result = $this->driver()->perform($handle);

            if ($result !== CURLE_OK) {
                throw new HttpClientException(
                    sprintf('%s %s failed: %s', strtoupper($method), $url, curl_strerror($result) ?? 'unknown error'),
                    $result,
                );
            }

            return new HttpResponse(
                (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
                $headers,
                (string) curl_multi_getcontent($handle),
            );
        } finally {
            curl_close($handle);
        }
    }

    public function reset(): void
    {
        $this->driver?->reset();
    }

    /**
     * @param Options $options
     * @return array<int, mixed>
     */
    private function curlOptions(string $method, string $url, array $options): array
    {
        $headers = $options['headers'] ?? [];
        $body = null;

        if (array_key_exists('json', $options)) {
            $body = json_encode($options['json'], JSON_THROW_ON_ERROR);
            $headers += ['Content-Type' => 'application/json'];
        } elseif (isset($options['form'])) {
            $body = http_build_query($options['form']);
            $headers += ['Content-Type' => 'application/x-www-form-urlencoded'];
        } elseif (isset($options['body'])) {
            $body = $options['body'];
        }

        if (isset($options['query']) && $options['query'] !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($options['query']);
        }

        $curl = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_PROTOCOLS_STR => 'http,https',
            CURLOPT_REDIR_PROTOCOLS_STR => 'http,https',
            CURLOPT_FOLLOWLOCATION => $options['follow_redirects'] ?? false,
            CURLOPT_MAXREDIRS => self::MAX_REDIRECTS,
            CURLOPT_TIMEOUT_MS => self::milliseconds($options['timeout'] ?? self::DEFAULT_TIMEOUT),
            CURLOPT_CONNECTTIMEOUT_MS => self::milliseconds($options['connect_timeout'] ?? self::DEFAULT_CONNECT_TIMEOUT),
            CURLOPT_HTTPHEADER => self::headerLines($headers),
        ];

        if ($method === 'HEAD') {
            $curl[CURLOPT_NOBODY] = true;
        }

        if ($body !== null) {
            $curl[CURLOPT_POSTFIELDS] = $body;
        }

        return $curl;
    }

    private function driver(): CurlDriver
    {
        return $this->driver ??= new CurlDriver($this->loop ?? EventLoop::instance());
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private static function collectHeader(array &$headers, string $line): void
    {
        // Each response in a redirect chain (or a 100 Continue) starts over.
        if (str_starts_with($line, 'HTTP/')) {
            $headers = [];
            return;
        }

        $separator = strpos($line, ':');

        if ($separator === false) {
            return;
        }

        $name = strtolower(trim(substr($line, 0, $separator)));
        $headers[$name][] = trim(substr($line, $separator + 1));
    }

    /**
     * @param array<string, string> $headers
     * @return list<string>
     */
    private static function headerLines(array $headers): array
    {
        $lines = [];

        foreach ($headers as $name => $value) {
            if (preg_match('/[\r\n]/', $name . $value) === 1) {
                throw new InvalidArgumentException("Invalid HTTP header: {$name}");
            }

            $lines[] = "{$name}: {$value}";
        }

        return $lines;
    }

    private static function milliseconds(float $seconds): int
    {
        return max(1, (int) round($seconds * 1000));
    }
}
