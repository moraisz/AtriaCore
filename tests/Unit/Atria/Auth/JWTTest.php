<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Auth;

use InvalidArgumentException;
use Atria\Modules\Auth\JWT;

test('encode produces three dot-separated segments', function () {
    $token = JWT::encode(['sub' => 1], 'secret');

    expect(count(explode('.', $token)))->toBe(3);
});

test('encode and decode round-trip returns original payload', function () {
    $payload = ['sub' => 42, 'role' => 'admin'];
    $token = JWT::encode($payload, 'secret');
    $decoded = JWT::decode($token, 'secret');

    expect($decoded)->toBe($payload);
});

test('encode with empty payload round-trips', function () {
    $token = JWT::encode([], 'secret');
    $decoded = JWT::decode($token, 'secret');

    expect($decoded)->toBe([]);
});

test('encode preserves mixed payload types', function () {
    $payload = ['str' => 'hello', 'int' => 7, 'bool' => true, 'arr' => ['nested' => 1]];
    $token = JWT::encode($payload, 'secret');
    $decoded = JWT::decode($token, 'secret');

    expect($decoded)->toBe($payload);
});

test('encode with short and long secrets both work', function () {
    $short = JWT::encode(['x' => 1], 'a');
    $long = JWT::encode(['x' => 1], str_repeat('k', 256));

    expect(JWT::decode($short, 'a'))->toBe(['x' => 1]);
    expect(JWT::decode($long, str_repeat('k', 256)))->toBe(['x' => 1]);
});

test('decode throws on less than three segments', function () {
    JWT::decode('one.two', 'secret');
})->throws(InvalidArgumentException::class, 'Invalid JWT format');

test('decode throws on more than three segments', function () {
    JWT::decode('one.two.three.four', 'secret');
})->throws(InvalidArgumentException::class, 'Invalid JWT format');

test('decode throws on tampered signature', function () {
    $token = JWT::encode(['sub' => 1], 'secret');
    $parts = explode('.', $token);
    $parts[1] = base64_encode(json_encode(['sub' => 999]));
    $tampered = implode('.', $parts);

    JWT::decode($tampered, 'secret');
})->throws(InvalidArgumentException::class, 'Invalid signature');

test('decode throws on unsupported algorithm', function () {
    $token = JWT::encode(['sub' => 1], 'secret');
    JWT::decode($token, 'secret', ['RS256']);
})->throws(InvalidArgumentException::class, 'Unsupported algorithm');

test('encode with explicit HS256 matches default', function () {
    $payload = ['sub' => 1];
    $default = JWT::encode($payload, 'secret');
    $explicit = JWT::encode($payload, 'secret', 'HS256');

    expect($explicit)->toBe($default);
});

// Memory leak — encode/decode loop
test('no memory leak after repeated encode and decode cycles', function () {
    $payload = ['sub' => 1, 'role' => 'user'];
    $secret = 'mem-test-secret';

    gc_collect_cycles();
    $startMemory = memory_get_usage();

    for ($i = 0; $i < 1000; $i++) {
        $token = JWT::encode($payload, $secret);
        JWT::decode($token, $secret);
    }

    gc_collect_cycles();
    $endMemory = memory_get_usage();

    $growth = $endMemory - $startMemory;
    expect($growth)->toBeLessThan(10_000);
});
