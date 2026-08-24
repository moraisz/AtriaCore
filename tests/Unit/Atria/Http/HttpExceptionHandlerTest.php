<?php

declare(strict_types=1);

use Atria\Modules\Csrf\Exceptions\CsrfTokenValidationException;
use Atria\Http\Exceptions\RouteDispatchException;
use Atria\Http\HttpExceptionHandler;
use Atria\Http\Exceptions\HttpException;
use Atria\Http\Request;
use Atria\Modules\Mercure\Exceptions\MercureTransportException;
use Atria\Modules\Csrf\CsrfManager;

function exceptionHandlerRequest(string $method, string $path, bool $json = false, ?string $referer = null): Request
{
    $request = new Request();
    $reflection = new ReflectionClass($request);
    $reflection->getProperty('method')->setValue($request, $method);
    $reflection->getProperty('path')->setValue($request, $path);
    $server = $json ? ['HTTP_CONTENT_TYPE' => 'application/json'] : [];
    if ($referer !== null) {
        $server['HTTP_REFERER'] = $referer;
    }
    $reflection->getProperty('server')->setValue($request, $server);

    return $request;
}

function exceptionHandler(): HttpExceptionHandler
{
    return new HttpExceptionHandler(new CsrfManager());
}

test('form http exception stores flash error and redirects back to form', function () {
    unset($_SESSION['error']);

    $response = exceptionHandler()->handle(
        new HttpException('Invalid credentials.', 401),
        exceptionHandlerRequest('POST', '/login'),
    );

    expect($response->getStatusCode())->toBe(302);
    expect($response->getHeader('Location'))->toBe('/login');
    expect($_SESSION['error'])->toBe('Invalid credentials.');
});

test('logout form error redirects to the referring page instead of GET logout', function () {
    $response = exceptionHandler()->handle(
        new CsrfTokenValidationException(),
        exceptionHandlerRequest('POST', '/logout', false, 'https://atria.test/'),
    );

    expect($response->getStatusCode())->toBe(302);
    expect($response->getHeader('Location'))->toBe('/');
});

test('json http exception keeps its status and does not create flash error', function () {
    unset($_SESSION['error']);

    $response = exceptionHandler()->handle(
        new HttpException('Invalid credentials.', 401),
        exceptionHandlerRequest('POST', '/login', true),
    );

    expect($response->getStatusCode())->toBe(401);
    expect($response->getHeader('Content-Type'))->toBe('application/json');
    expect($_SESSION)->not->toHaveKey('error');
});

test('json csrf exception rotates token and exposes it in response header', function () {
    $_SESSION['csrf_token'] = 'stale-token';

    $response = exceptionHandler()->handle(
        new CsrfTokenValidationException(),
        exceptionHandlerRequest('POST', '/mercure/publish', true),
    );

    $rotatedToken = $response->getHeader('X-CSRF-Token');

    expect($response->getStatusCode())->toBe(403)
        ->and($response->getHeader('Content-Type'))->toBe('application/json')
        ->and($rotatedToken)->toBeString()
        ->and($rotatedToken)->not->toBe('stale-token')
        ->and($_SESSION['csrf_token'])->toBe($rotatedToken);
});

test('unexpected exception returns generic internal server error', function () {
    $response = exceptionHandler()->handle(new RuntimeException('secret details'), null);

    expect($response->getStatusCode())->toBe(500);
    expect($response->getContent())->not->toContain('secret details');
});

test('route dispatch exception returns generic internal server error', function () {
    $response = exceptionHandler()->handle(
        new RouteDispatchException('Invalid controller'),
        exceptionHandlerRequest('GET', '/broken', true),
    );

    expect($response->getStatusCode())->toBe(500);
    expect($response->getContent())->not->toContain('Invalid controller');
});

test('mercure transport exception returns a 502 json response', function () {
    $response = exceptionHandler()->handle(
        new MercureTransportException('Hub unavailable'),
        exceptionHandlerRequest('POST', '/mercure/publish', true),
    );

    expect($response->getStatusCode())->toBe(502)
        ->and($response->getHeader('Content-Type'))->toBe('application/json')
        ->and($response->getContent())->toContain('Hub unavailable');
});
