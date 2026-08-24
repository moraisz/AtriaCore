<?php

declare(strict_types=1);

use Atria\Modules\Mercure\Exceptions\MercureTransportException;
use Atria\Modules\Mercure\MercureConfig;
use Atria\Modules\Mercure\MercurePublisher;

test('publish sends expected payload to the native mercure publisher', function () {
    $capturedTopics = null;
    $capturedData = null;
    $capturedPrivate = null;
    $capturedId = null;
    $capturedType = null;
    $capturedRetry = null;

    $publisher = new MercurePublisher(
        new MercureConfig(true, '/.well-known/mercure', 'subscriber-secret', 3600, ''),
        function (
            array|string $topics,
            string $data,
            bool $private,
            ?string $id,
            ?string $type,
            ?int $retry,
        ) use (
            &$capturedTopics,
            &$capturedData,
            &$capturedPrivate,
            &$capturedId,
            &$capturedType,
            &$capturedRetry,
        ): string {
            $capturedTopics = $topics;
            $capturedData = $data;
            $capturedPrivate = $private;
            $capturedId = $id;
            $capturedType = $type;
            $capturedRetry = $retry;

            return 'update-1';
        },
    );

    $publisher->publish(['users/1', 'orders/2'], '{"ok":true}', true, 'update-1', 'user.updated', 3000);

    expect($capturedTopics)->toBe(['users/1', 'orders/2'])
        ->and($capturedData)->toBe('{"ok":true}')
        ->and($capturedPrivate)->toBeTrue()
        ->and($capturedId)->toBe('update-1')
        ->and($capturedType)->toBe('user.updated')
        ->and($capturedRetry)->toBe(3000);
});

test('publish passes raw string data through to the native mercure publisher', function () {
    $capturedData = null;

    $publisher = new MercurePublisher(
        new MercureConfig(true, '/.well-known/mercure', 'subscriber-secret', 3600, ''),
        function (
            array|string $topics,
            string $data,
            bool $private,
            ?string $id,
            ?string $type,
            ?int $retry,
        ) use (&$capturedData): string {
            $capturedData = $data;

            return 'update-2';
        },
    );

    $publisher->publish('users/1', 'hello world');

    expect($capturedData)->toBe('hello world');
});

test('publish requires string data', function () {
    $publisher = new MercurePublisher(new MercureConfig(true, '/.well-known/mercure', 'subscriber-secret', 3600, ''));

    $publisher->publish('users/1', ['message' => 'hello']);
})->throws(TypeError::class);

test('publish wraps transport failures as mercure transport exceptions', function () {
    $publisher = new MercurePublisher(
        new MercureConfig(true, '/.well-known/mercure', 'subscriber-secret', 3600, ''),
        static function (): string {
            throw new RuntimeException('Hub unavailable');
        },
    );

    $publisher->publish('users/1', '{"ok":true}');
})->throws(MercureTransportException::class, 'Hub unavailable');

test('publisher cannot be resolved when Mercure is disabled', function () {
    new MercurePublisher(new MercureConfig(false, '', '', 3600, ''));
})->throws(RuntimeException::class, 'Mercure is disabled.');
