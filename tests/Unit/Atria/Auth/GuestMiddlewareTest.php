<?php

declare(strict_types=1);

use Atria\Database\Contracts\QueryBuilder;
use Atria\Modules\Auth\AuthConfig;
use Atria\Modules\Auth\AuthManager;
use Atria\Modules\Auth\JWT;
use Atria\Modules\Auth\Middlewares\GuestMiddleware;
use Atria\Modules\Auth\Services\AuthTokenService;
use Atria\Database\QueryBuilders\PgSqlQueryBuilder;
use Atria\Http\Request;
use Atria\Http\Response;

beforeEach(function () {
    putenv('APP_KEY=test-secret');
});

afterEach(function () {
    putenv('APP_KEY');
});

/** @param array<string, string> $cookies */
function coreGuestCookieRequest(array $cookies): Request
{
    $request = new Request();
    $reflection = new ReflectionClass($request);
    $reflection->getProperty('cookies')->setValue($request, $cookies);

    return $request;
}

function coreGuestAccessToken(int $expiresAt, string $secret = 'test-secret'): string
{
    return JWT::encode([
        'sub' => 7,
        'email' => 'user@test.com',
        'exp' => $expiresAt,
        'type' => 'access',
    ], $secret);
}

function coreGuestMiddleware(): GuestMiddleware
{
    $connection = new MockDatabaseConnection();

    return new GuestMiddleware(new AuthManager(
        static fn(): QueryBuilder => new PgSqlQueryBuilder($connection),
        new AuthTokenService('test-secret'),
        new AuthConfig(
            driver: 'standard',
            secret: 'test-secret',
            accessTtl: 300,
            refreshTtl: 86400,
            accessCookie: 'access_token',
            refreshCookie: 'refresh_token',
            cookieSecure: true,
            cookieSameSite: 'Strict',
            redirectGuestTo: '/login',
            redirectAuthenticatedTo: '/',
            usersTable: 'users',
            refreshTokensTable: 'refresh_tokens',
        ),
    ));
}

test('no cookies allows access to guest route without clearing cookies', function () {
    $called = false;

    $result = coreGuestMiddleware()->handle(
        coreGuestCookieRequest([]),
        new Response(),
        function (Request $request, Response $response) use (&$called): Response {
            $called = true;

            return $response;
        },
    );

    expect($called)->toBeTrue();
    expect($result->getStatusCode())->toBe(200);
    expect($result->getCookies())->toHaveCount(0);
});

test('valid access cookie redirects to home', function () {
    $called = false;

    $result = coreGuestMiddleware()->handle(
        coreGuestCookieRequest(['access_token' => coreGuestAccessToken(time() + 300)]),
        new Response(),
        function () use (&$called): Response {
            $called = true;

            return new Response();
        },
    );

    expect($called)->toBeFalse();
    expect($result->getStatusCode())->toBe(302);
    expect($result->getHeader('Location'))->toBe('/');
});

test('expired access with refresh cookie defers validation and rotation to home', function () {
    $called = false;

    $result = coreGuestMiddleware()->handle(
        coreGuestCookieRequest([
            'access_token' => coreGuestAccessToken(time() - 1),
            'refresh_token' => 'refresh-token',
        ]),
        new Response(),
        function () use (&$called): Response {
            $called = true;

            return new Response();
        },
    );

    expect($called)->toBeFalse();
    expect($result->getStatusCode())->toBe(302);
    expect($result->getHeader('Location'))->toBe('/');
    expect($result->getCookies())->toHaveCount(0);
});

test('invalid access without refresh clears cookies and allows guest access', function () {
    $called = false;

    $result = coreGuestMiddleware()->handle(
        coreGuestCookieRequest(['access_token' => coreGuestAccessToken(time() + 300, 'wrong-secret')]),
        new Response(),
        function (Request $request, Response $response) use (&$called): Response {
            $called = true;

            return $response;
        },
    );

    expect($called)->toBeTrue();
    expect($result->getCookies())->toHaveCount(2);
});

test('expired access without refresh clears cookies and allows guest access', function () {
    $called = false;

    $result = coreGuestMiddleware()->handle(
        coreGuestCookieRequest(['access_token' => coreGuestAccessToken(time() - 1)]),
        new Response(),
        function (Request $request, Response $response) use (&$called): Response {
            $called = true;

            return $response;
        },
    );

    expect($called)->toBeTrue();
    expect($result->getCookies())->toHaveCount(2);
});
