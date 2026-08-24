<?php

declare(strict_types=1);

use Atria\Modules\Auth\JWT;
use Atria\Modules\Auth\Services\AuthTokenService;

test('issuePair returns tokens and the refresh jti hash without persistence', function () {
    $service = new AuthTokenService('test-secret');
    $pair = $service->issuePair(7, 'user@test.com');
    $refresh = JWT::decode($pair['refresh_token'], 'test-secret');

    expect($pair['access_token'])->toBeString();
    expect($pair['refresh_token'])->toBeString();
    expect($pair['refresh_token_hash'])->toMatch('/^[a-f0-9]{64}$/');
    expect($refresh['jti'])->not->toBe($pair['refresh_token_hash']);
    expect(hash('sha256', $refresh['jti']))->toBe($pair['refresh_token_hash']);
});

test('issuePair tokens carry correct claims', function () {
    $pair = (new AuthTokenService('test-secret'))->issuePair(7, 'user@test.com');
    $access = JWT::decode($pair['access_token'], 'test-secret');
    $refresh = JWT::decode($pair['refresh_token'], 'test-secret');

    expect($access['sub'])->toBe(7);
    expect($access['email'])->toBe('user@test.com');
    expect($access['type'])->toBe('access');
    expect($access['exp'])->toBeGreaterThan(time());
    expect($refresh['type'])->toBe('refresh');
});

test('issuePair preserves absolute refresh expiration during rotation', function () {
    $sessionExpiresAt = time() + 900;
    $pair = (new AuthTokenService('test-secret'))->issuePair(7, 'user@test.com', $sessionExpiresAt);
    $refresh = JWT::decode($pair['refresh_token'], 'test-secret');

    expect($refresh['exp'])->toBe($sessionExpiresAt);
    expect($pair['refresh_expires_at'])->toBe($sessionExpiresAt);
});

test('decode rejects malformed claims and wrong token type', function () {
    $service = new AuthTokenService('test-secret');
    $wrongType = JWT::encode(['sub' => 7, 'email' => 'user@test.com', 'exp' => time() + 60, 'type' => 'refresh', 'jti' => 'id'], 'test-secret');
    $missingSubject = JWT::encode(['email' => 'user@test.com', 'exp' => time() + 60, 'type' => 'access'], 'test-secret');

    expect(fn() => $service->decodeAccessToken($wrongType))->toThrow(InvalidArgumentException::class);
    expect(fn() => $service->decodeAccessToken($missingSubject))->toThrow(InvalidArgumentException::class);
});
