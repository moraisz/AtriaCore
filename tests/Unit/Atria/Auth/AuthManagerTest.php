<?php

declare(strict_types=1);

use Atria\Database\Contracts\QueryBuilder;
use Atria\Modules\Auth\AuthConfig;
use Atria\Modules\Auth\AuthManager;
use Atria\Modules\Auth\Exceptions\InvalidCredentialsException;
use Atria\Modules\Auth\Exceptions\InvalidRefreshTokenException;
use Atria\Modules\Auth\Exceptions\RefreshTokenExpiredException;
use Atria\Modules\Auth\Services\AuthTokenService;
use Atria\Modules\Auth\Data\AuthenticatedPrincipal;
use Atria\Database\QueryBuilders\PgSqlQueryBuilder;
use Atria\Http\Response;

function coreAuthConfig(): AuthConfig
{
    return new AuthConfig(
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
    );
}

function coreAuthManager(?MockDatabaseConnection $connection = null): array
{
    $connection ??= new MockDatabaseConnection();

    return [
        'connection' => $connection,
        'manager' => new AuthManager(
            static fn(): QueryBuilder => new PgSqlQueryBuilder($connection),
            new AuthTokenService('test-secret'),
            coreAuthConfig(),
        ),
    ];
}

test('attempt issues a token pair and stores the refresh hash', function () {
    $connection = new MockDatabaseConnection();
    $connection->setReturnRows([[
        'id' => 42,
        'name' => 'John',
        'email' => 'john@test.com',
        'password_hash' => password_hash('secret', PASSWORD_BCRYPT),
    ]]);

    $manager = coreAuthManager($connection)['manager'];
    $result = $manager->attempt('john@test.com', 'secret', 'curl');

    expect($result['user'])->toEqual(new AuthenticatedPrincipal(42, 'john@test.com', 'John'));
    expect($result['access_token'])->toBeString();
    expect($result['refresh_token'])->toBeString();
    expect($connection->executedQueries)->toHaveCount(2);
    expect($connection->executedQueries[0])->toBe('SELECT id, name, email, password_hash FROM users WHERE email = ? LIMIT 1');
    expect($connection->executedQueries[1])->toContain('INSERT INTO refresh_tokens');
    expect($connection->executedBindings[0])->toBe(['john@test.com']);
    expect($connection->executedBindings[1][0])->toBe(42);
    expect($connection->executedBindings[1][2])->toBe('curl');
});

test('attempt throws when credentials are invalid', function () {
    $manager = coreAuthManager(new MockDatabaseConnection())['manager'];

    $manager->attempt('john@test.com', 'wrong');
})->throws(InvalidCredentialsException::class, 'Invalid credentials.');

test('issuePairForUser stores refresh token metadata for a known user', function () {
    $connection = new MockDatabaseConnection();
    $manager = coreAuthManager($connection)['manager'];
    $tokens = $manager->issuePairForUser(new AuthenticatedPrincipal(7, 'user@test.com', 'User'), 'browser');

    expect($tokens['refresh_token_hash'])->toBeString();
    expect($connection->executedQueries)->toHaveCount(1);
    expect($connection->executedQueries[0])->toContain('INSERT INTO refresh_tokens');
    expect($connection->executedBindings[0][0])->toBe(7);
    expect($connection->executedBindings[0][2])->toBe('browser');
});

test('refresh rotates atomically and preserves absolute expiration', function () {
    $connection = new MockDatabaseConnection();
    $connection->setReturnRows([['user_id' => 7]]);
    $manager = coreAuthManager($connection)['manager'];
    $sessionExpiresAt = time() + 1200;
    $pair = $manager->issuePairForUser(new AuthenticatedPrincipal(7, 'user@test.com'), 'browser', $sessionExpiresAt);

    $rotated = $manager->refresh($pair['refresh_token'], 'curl');

    expect($rotated['access_token'])->toBeString();
    expect($rotated['refresh_token'])->toBeString();
    expect($connection->executedQueries[1])->toContain('WITH consumed AS');
    expect($connection->executedBindings[1][1])->toBe(7);
    expect($connection->executedBindings[1][5])->toBe('curl');
    expect($connection->executedBindings[1][6])->toBe(date('Y-m-d H:i:s', $sessionExpiresAt));
});

test('refresh throws when the token was revoked or replayed', function () {
    $connection = new MockDatabaseConnection();
    $manager = coreAuthManager($connection)['manager'];
    $pair = $manager->issuePairForUser(new AuthenticatedPrincipal(7, 'user@test.com'));

    $manager->refresh($pair['refresh_token']);
})->throws(InvalidRefreshTokenException::class, 'Refresh token not found or revoked');

test('refresh throws on expired token', function () {
    $manager = coreAuthManager(new MockDatabaseConnection())['manager'];
    $pair = $manager->issuePairForUser(new AuthenticatedPrincipal(7, 'user@test.com'), refreshExpiresAt: time() - 1);

    $manager->refresh($pair['refresh_token']);
})->throws(RefreshTokenExpiredException::class, 'Refresh token expired');

test('logout revokes the active refresh token and clearCookies expires both cookies', function () {
    $connection = new MockDatabaseConnection();
    $manager = coreAuthManager($connection)['manager'];
    $pair = $manager->issuePairForUser(new AuthenticatedPrincipal(7, 'user@test.com'));
    $response = new Response();

    $manager->logout($pair['refresh_token']);
    $manager->clearCookies($response);

    expect($connection->executedQueries[1])->toContain('UPDATE refresh_tokens SET revoked_at = ? WHERE user_id = ? AND token_hash = ?');
    expect($response->getCookies())->toHaveCount(2);
    expect(implode(' ', $response->getCookies()))->toContain('access_token=')->toContain('refresh_token=');
});

test('logout ignores invalid refresh tokens', function () {
    $connection = new MockDatabaseConnection();
    $manager = coreAuthManager($connection)['manager'];

    $manager->logout('invalid-token');

    expect($connection->executedQueries)->toBe([]);
});
