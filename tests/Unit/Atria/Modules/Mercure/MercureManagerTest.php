<?php

declare(strict_types=1);

use Atria\Modules\Auth\JWT;
use Atria\Modules\Mercure\MercureConfig;
use Atria\Modules\Mercure\MercureManager;

function mercureConfig(array $overrides = []): MercureConfig
{
    return MercureConfig::fromArray($overrides + [
        'enabled' => true,
        'hub_url' => '/.well-known/mercure',
        'subscribe_jwt_key' => 'subscriber-secret',
        'subscribe_jwt_ttl' => 3600,
        'authorization_cookie_domain' => '',
    ]);
}

function mercureManager(MercureConfig $config): MercureManager
{
    return new MercureManager($config);
}

test('MercureConfig validates typed configuration values', function () {
    MercureConfig::fromArray([
        'enabled' => true,
        'hub_url' => '/.well-known/mercure',
        'subscribe_jwt_key' => 'subscriber-secret',
        'subscribe_jwt_ttl' => '3600',
        'authorization_cookie_domain' => '',
    ]);
})->throws(InvalidArgumentException::class, 'Mercure config subscribe_jwt_ttl must be an integer.');

test('hubUrl returns configured public url', function () {
    expect(mercureManager(mercureConfig())->hubUrl())->toBe('/.well-known/mercure');
});

test('subscribeUrl appends one or many topics', function () {
    $manager = mercureManager(mercureConfig());

    expect($manager->subscribeUrl('users/1'))->toBe('/.well-known/mercure?topic=users%2F1');
    expect($manager->subscribeUrl(['users/1', 'orders/2']))
        ->toBe('/.well-known/mercure?topic=users%2F1&topic=orders%2F2');
});

test('discoveryLink returns configured hub link header value', function () {
    expect(mercureManager(mercureConfig())->discoveryLink())
        ->toBe('</.well-known/mercure>; rel="mercure"');
});

test('subscribeToken encodes subscriber topics', function () {
    $token = mercureManager(mercureConfig(['subscribe_jwt_ttl' => 600]))
        ->subscribeToken(['users/1', 'orders/2']);
    $payload = JWT::decode($token, 'subscriber-secret');

    expect($payload['mercure']['subscribe'])->toBe(['users/1', 'orders/2'])
        ->and($payload['exp'])->toBeGreaterThan(time())
        ->and($payload['exp'])->toBeLessThanOrEqual(time() + 600);
});

test('throws when mercure is disabled', function () {
    mercureManager(mercureConfig(['enabled' => false]))->hubUrl();
})->throws(RuntimeException::class, 'Mercure is disabled.');

test('subscribeToken throws when subscriber key is missing', function () {
    mercureManager(mercureConfig(['subscribe_jwt_key' => '']))->subscribeToken('users/1');
})->throws(RuntimeException::class, 'Mercure subscribe JWT key is required.');
