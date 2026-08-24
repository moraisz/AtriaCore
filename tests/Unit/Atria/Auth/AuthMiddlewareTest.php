<?php

declare(strict_types=1);

use Atria\Database\Contracts\QueryBuilder;
use Atria\Modules\Auth\AuthConfig;
use Atria\Modules\Auth\AuthManager;
use Atria\Modules\Auth\JWT;
use Atria\Modules\Auth\Middlewares\AuthMiddleware;
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
function coreAuthCookieRequest(array $cookies): Request
{
    $request = new Request();
    $reflection = new ReflectionClass($request);
    $reflection->getProperty('cookies')->setValue($request, $cookies);

    return $request;
}

function coreAuthAccessToken(int $expiresAt, string $secret = 'test-secret'): string
{
    return JWT::encode([
        'sub' => 7,
        'email' => 'user@test.com',
        'exp' => $expiresAt,
        'type' => 'access',
    ], $secret);
}

function coreAuthMiddleware(?MockDatabaseConnection $connection = null): AuthMiddleware
{
    $connection ??= new MockDatabaseConnection();

    return new AuthMiddleware(new AuthManager(
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

test('valid access cookie allows request and preserves route params', function () {
    $request = coreAuthCookieRequest(['access_token' => coreAuthAccessToken(time() + 300)]);
    $request->setParams(['id' => '42']);
    $called = false;

    $result = coreAuthMiddleware()->handle(
        $request,
        new Response(),
        function (Request $request, Response $response) use (&$called): Response {
            $called = true;
            expect($request->getParam('id'))->toBe('42');
            expect($request->getAttribute('auth_user_id'))->toBe(7);
            expect($request->getAttribute('auth_email'))->toBe('user@test.com');
            expect($request->getAttribute('auth_user'))->toBeInstanceOf(Atria\Modules\Auth\Data\AuthenticatedPrincipal::class);

            return $response;
        },
    );

    expect($called)->toBeTrue();
    expect($result->getStatusCode())->toBe(200);
});

test('missing or invalid access cookie clears authentication and redirects', function (array $cookies) {
    $called = false;
    $result = coreAuthMiddleware()->handle(
        coreAuthCookieRequest($cookies),
        new Response(),
        function () use (&$called): Response {
            $called = true;
            return new Response();
        },
    );

    expect($called)->toBeFalse();
    expect($result->getStatusCode())->toBe(302);
    expect($result->getHeader('Location'))->toBe('/login');
    expect($result->getCookies())->toHaveCount(2);
})->with([
    'missing' => [[]],
    'tampered' => [['access_token' => coreAuthAccessToken(time() + 300, 'wrong-secret')]],
]);

test('expired access with valid refresh rotates both cookies without extending session', function () {
    $connection = new MockDatabaseConnection();
    $connection->setReturnRows([['user_id' => 7]]);
    $service = new AuthTokenService('test-secret');
    $sessionExpiresAt = time() + 1200;
    $pair = $service->issuePair(7, 'user@test.com', $sessionExpiresAt);
    $called = false;

    $result = coreAuthMiddleware($connection)->handle(
        coreAuthCookieRequest([
            'access_token' => coreAuthAccessToken(time() - 1),
            'refresh_token' => $pair['refresh_token'],
        ]),
        new Response(),
        function () use (&$called): Response {
            $called = true;
            return new Response();
        },
    );

    expect($called)->toBeTrue();
    expect($connection->executedQueries[0])->toContain('WITH consumed AS');
    expect($connection->executedBindings[0][6])->toBe(date('Y-m-d H:i:s', $sessionExpiresAt));
    expect($result->getCookies())->toHaveCount(2);
    expect(implode(' ', $result->getCookies()))->toContain('Secure')->toContain('HttpOnly')->toContain('SameSite=Strict');
});

test('expired access with expired refresh redirects without calling protected route', function () {
    $refresh = JWT::encode([
        'sub' => 7,
        'email' => 'user@test.com',
        'exp' => time() - 1,
        'type' => 'refresh',
        'jti' => 'expired-session',
    ], 'test-secret');
    $called = false;

    $result = coreAuthMiddleware()->handle(
        coreAuthCookieRequest(['access_token' => coreAuthAccessToken(time() - 1), 'refresh_token' => $refresh]),
        new Response(),
        function () use (&$called): Response {
            $called = true;
            return new Response();
        },
    );

    expect($called)->toBeFalse();
    expect($result->getStatusCode())->toBe(302);
    expect($result->getHeader('Location'))->toBe('/login');
});

test('expired access with revoked refresh redirects', function () {
    $pair = (new AuthTokenService('test-secret'))->issuePair(7, 'user@test.com');

    $result = coreAuthMiddleware(new MockDatabaseConnection())->handle(
        coreAuthCookieRequest(['access_token' => coreAuthAccessToken(time() - 1), 'refresh_token' => $pair['refresh_token']]),
        new Response(),
        fn(Request $request, Response $response): Response => $response,
    );

    expect($result->getStatusCode())->toBe(302);
    expect($result->getHeader('Location'))->toBe('/login');
});
