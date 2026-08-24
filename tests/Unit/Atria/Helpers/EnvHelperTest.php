<?php

declare(strict_types=1);

use Atria\Helpers\EnvHelper;

test('env returns value when set', function () {
    putenv('ATRIA_CORE_TEST_FOO=bar');
    expect(EnvHelper::env('ATRIA_CORE_TEST_FOO'))->toBe('bar');
    putenv('ATRIA_CORE_TEST_FOO');
});

test('env returns default when not set', function () {
    putenv('ATRIA_CORE_TEST_MISSING');
    expect(EnvHelper::env('ATRIA_CORE_TEST_MISSING', 'fallback'))->toBe('fallback');
});

test('env returns null default when omitted', function () {
    putenv('ATRIA_CORE_TEST_UNSET');
    expect(EnvHelper::env('ATRIA_CORE_TEST_UNSET'))->toBeNull();
});

test('env returns string for numeric-looking env values', function () {
    putenv('ATRIA_CORE_TEST_NUM=42');
    expect(EnvHelper::env('ATRIA_CORE_TEST_NUM'))->toBe('42');
    putenv('ATRIA_CORE_TEST_NUM');
});

test('env returns empty string', function () {
    putenv('ATRIA_CORE_TEST_EMPTY=');
    expect(EnvHelper::env('ATRIA_CORE_TEST_EMPTY'))->toBe('');
    putenv('ATRIA_CORE_TEST_EMPTY');
});
