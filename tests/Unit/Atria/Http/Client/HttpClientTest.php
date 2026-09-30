<?php

declare(strict_types=1);

use Atria\Async\Async;
use Atria\Async\EventLoop;
use Atria\Http\Client\Exceptions\HttpClientException;
use Atria\Http\Client\HttpClient;

/*
 * Runs against local `php -S` servers (tests/Fixtures/http-server/router.php),
 * started on first use. Each server handles one request at a time, so tests
 * that need parallel responses use a different server per request.
 */

final class HttpTestServer
{
    /** @var array<int, resource> */
    private static array $processes = [];

    /** @var array<int, string> */
    private static array $baseUrls = [];

    public static function url(string $path, int $server = 0): string
    {
        if (!isset(self::$processes[$server])) {
            self::start($server);
        }

        return self::$baseUrls[$server] . $path;
    }

    public static function stop(): void
    {
        foreach (self::$processes as $process) {
            proc_terminate($process);
            proc_close($process);
        }

        self::$processes = [];
        self::$baseUrls = [];
    }

    /**
     * A local port nothing listens on.
     */
    public static function closedPort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');

        if ($socket === false) {
            throw new RuntimeException('Could not reserve a local port.');
        }

        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    private static function start(int $server): void
    {
        $port = self::closedPort();
        $router = dirname(__DIR__, 4) . '/Fixtures/http-server/router.php';

        $process = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", $router],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );

        if ($process === false) {
            throw new RuntimeException('Could not start the test HTTP server.');
        }

        self::$processes[$server] = $process;
        self::$baseUrls[$server] = "http://127.0.0.1:{$port}";

        // Connection refused is expected until the server is up.
        set_error_handler(static fn(): bool => true);

        try {
            for ($i = 0; $i < 100; $i++) {
                $connection = fsockopen('127.0.0.1', $port);

                if ($connection !== false) {
                    fclose($connection);
                    return;
                }

                usleep(20_000);
            }
        } finally {
            restore_error_handler();
        }

        throw new RuntimeException('The test HTTP server did not start.');
    }
}

afterAll(fn() => HttpTestServer::stop());

beforeEach(function () {
    EventLoop::instance()->reset();
    $this->http = new HttpClient();
});

test('sends method, query, headers and a JSON body', function () {
    $response = $this->http->post(HttpTestServer::url('/echo'), [
        'query' => ['page' => 2],
        'headers' => ['X-Custom' => 'yes'],
        'json' => ['name' => 'ana'],
    ]);

    expect($response->status)->toBe(200)
        ->and($response->ok())->toBeTrue()
        ->and($response->json())->toBe([
            'method' => 'POST',
            'query' => ['page' => '2'],
            'content_type' => 'application/json',
            'custom' => 'yes',
            'body' => '{"name":"ana"}',
        ]);
});

test('sends form bodies and exposes response headers', function () {
    $response = $this->http->put(HttpTestServer::url('/echo'), ['form' => ['a' => '1', 'b' => 'x y']]);
    $echo = $response->json();

    expect(is_array($echo) ? $echo['body'] : null)->toBe('a=1&b=x+y')
        ->and($response->header('Content-Type'))->toBe('application/json')
        ->and($response->headers['x-multi'])->toBe(['first', 'second']);
});

test('error statuses are responses, not exceptions', function () {
    $response = $this->http->get(HttpTestServer::url('/status/503'));

    expect($response->status)->toBe(503)
        ->and($response->ok())->toBeFalse()
        ->and($response->body)->toBe('status 503');
});

test('redirects are followed only when asked', function () {
    $url = HttpTestServer::url('/redirect');

    expect($this->http->get($url)->status)->toBe(302);

    $followed = $this->http->get($url, ['follow_redirects' => true]);
    $echo = $followed->json();

    expect($followed->status)->toBe(200)
        ->and(is_array($echo) ? $echo['query'] : null)->toBe(['redirected' => '1'])
        ->and($followed->header('location'))->toBeNull();
});

test('transport failures raise HttpClientException', function () {
    $port = HttpTestServer::closedPort();

    expect(fn() => $this->http->get("http://127.0.0.1:{$port}/"))
        ->toThrow(HttpClientException::class);

    try {
        $this->http->get(HttpTestServer::url('/sleep?ms=2000'), ['timeout' => 0.2]);
        $this->fail('Expected a timeout');
    } catch (HttpClientException $e) {
        expect($e->getCode())->toBe(CURLE_OPERATION_TIMEDOUT);
    }
});

test('only http and https are allowed', function () {
    expect(fn() => $this->http->get('file:///etc/passwd'))
        ->toThrow(HttpClientException::class);
});

test('header values cannot inject extra headers', function () {
    expect(fn() => $this->http->get(HttpTestServer::url('/echo'), ['headers' => ['X-Custom' => "a\r\nX-Evil: 1"]]))
        ->toThrow(InvalidArgumentException::class);
});

test('requests inside concurrently() overlap', function () {
    $urls = array_map(static fn(int $server): string => HttpTestServer::url('/sleep?ms=200', $server), [1, 2, 3]);
    $start = hrtime(true);

    $bodies = Async::concurrently(
        ...array_map(fn(string $url): Closure => fn() => $this->http->get($url)->body, $urls),
    );

    expect($bodies)->toBe(['slept', 'slept', 'slept'])
        ->and((hrtime(true) - $start) / 1e9)->toBeLessThan(0.45);
});

test('reset drops transfers left by an aborted task and keeps the client usable', function () {
    $loop = EventLoop::instance();
    $loop->async(fn() => $this->http->get(HttpTestServer::url('/sleep?ms=2000')));
    $loop->delay(0.05, static fn() => null);
    $loop->tick();
    $loop->tick();

    $loop->reset();
    $this->http->reset();

    expect($this->http->get(HttpTestServer::url('/status/204'))->status)->toBe(204);
});
