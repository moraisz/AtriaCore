<?php

declare(strict_types=1);

use Atria\Http\Request;

test('setParams and getParam', function () {
    $request = new Request();
    $request->setParams(['id' => '42', 'slug' => 'hello-world']);

    expect($request->getParam('id'))->toBe('42');
    expect($request->getParam('slug'))->toBe('hello-world');
    expect($request->getParam('missing'))->toBeNull();
    expect($request->getParam('missing', 'default'))->toBe('default');
});

test('getParams returns all params', function () {
    $request = new Request();
    $request->setParams(['a' => '1', 'b' => '2']);

    expect($request->getParams())->toBe(['a' => '1', 'b' => '2']);
});

test('getMethod returns empty string when not set from globals', function () {
    $request = new Request();
    expect($request->getMethod())->toBe('');
});

test('getPath returns empty string when not set from globals', function () {
    $request = new Request();
    expect($request->getPath())->toBe('');
});

test('getHeader resolves HTTP_ prefixed server keys', function () {
    $request = new Request();

    $ref = new ReflectionClass($request);
    $prop = $ref->getProperty('server');
    $prop->setValue($request, ['HTTP_CONTENT_TYPE' => 'application/json', 'HTTP_X_TOKEN' => 'abc']);

    expect($request->getHeader('Content-Type'))->toBe('application/json');
    expect($request->getHeader('X-Token'))->toBe('abc');
    expect($request->getHeader('X-Missing'))->toBeNull();
});

test('isJson detects json content type', function () {
    $request = new Request();

    $ref = new ReflectionClass($request);
    $prop = $ref->getProperty('server');
    $prop->setValue($request, ['HTTP_CONTENT_TYPE' => 'application/json']);

    expect($request->isJson())->toBeTrue();
});

test('isJson returns false for text/html', function () {
    $request = new Request();

    $ref = new ReflectionClass($request);
    $prop = $ref->getProperty('server');
    $prop->setValue($request, ['HTTP_CONTENT_TYPE' => 'text/html']);

    expect($request->isJson())->toBeFalse();
});

test('isJson returns false for missing content type', function () {
    $request = new Request();
    expect($request->isJson())->toBeFalse();
});

test('getQuery returns full array or single value', function () {
    $request = new Request();

    $ref = new ReflectionClass($request);
    $prop = $ref->getProperty('query');
    $prop->setValue($request, ['page' => '2', 'sort' => 'desc']);

    expect($request->getQuery())->toBe(['page' => '2', 'sort' => 'desc']);
    expect($request->getQuery('page'))->toBe('2');
    expect($request->getQuery('missing', 'fallback'))->toBe('fallback');
});

test('getBody returns full array or single value', function () {
    $request = new Request();

    $ref = new ReflectionClass($request);
    $prop = $ref->getProperty('body');
    $prop->setValue($request, ['name' => 'John', 'email' => 'john@test.com']);

    expect($request->getBody())->toBe(['name' => 'John', 'email' => 'john@test.com']);
    expect($request->getBody('name'))->toBe('John');
    expect($request->getBody('missing'))->toBeNull();
});

test('bodyString returns strings and falls back for non strings', function () {
    $request = new Request();

    $ref = new ReflectionClass($request);
    $prop = $ref->getProperty('body');
    $prop->setValue($request, ['name' => 'John', 'meta' => ['x' => 'y']]);

    expect($request->bodyString('name'))->toBe('John');
    expect($request->bodyString('missing'))->toBe('');
    expect($request->bodyString('meta', 'fallback'))->toBe('fallback');
});

test('bodyOptionalString trims strings and returns null for empty values', function () {
    $request = new Request();

    $ref = new ReflectionClass($request);
    $prop = $ref->getProperty('body');
    $prop->setValue($request, ['name' => '  Atria  ', 'empty' => '   ']);

    expect($request->bodyOptionalString('name'))->toBe('Atria');
    expect($request->bodyOptionalString('empty'))->toBeNull();
    expect($request->bodyOptionalString('missing'))->toBeNull();
});

test('bodyBool coerces request values to boolean', function () {
    $request = new Request();

    $ref = new ReflectionClass($request);
    $prop = $ref->getProperty('body');
    $prop->setValue($request, [
        'enabled' => 'true',
        'disabled' => '0',
        'invalid' => 'nope',
    ]);

    expect($request->bodyBool('enabled'))->toBeTrue();
    expect($request->bodyBool('disabled', true))->toBeFalse();
    expect($request->bodyBool('invalid', true))->toBeTrue();
    expect($request->bodyBool('missing'))->toBeFalse();
});

test('bodyInt validates integers and minimum values', function () {
    $request = new Request();

    $ref = new ReflectionClass($request);
    $prop = $ref->getProperty('body');
    $prop->setValue($request, [
        'string' => '7',
        'int' => 3,
        'invalid' => 'abc',
        'negative' => '-1',
        'empty' => '',
    ]);

    expect($request->bodyInt('string'))->toBe(7);
    expect($request->bodyInt('int'))->toBe(3);
    expect($request->bodyInt('missing', 9))->toBe(9);
    expect($request->bodyInt('empty', 5))->toBe(5);
    expect($request->bodyInt('invalid'))->toBeNull();
    expect($request->bodyInt('negative', null, 0))->toBeNull();
});

test('bodyStringList normalizes strings and arrays', function () {
    $request = new Request();

    $ref = new ReflectionClass($request);
    $prop = $ref->getProperty('body');
    $prop->setValue($request, [
        'single' => ' users/1 ',
        'many' => [' users/1 ', '', 'orders/2', 10],
    ]);

    expect($request->bodyStringList('single'))->toBe(['users/1']);
    expect($request->bodyStringList('many'))->toBe(['users/1', 'orders/2']);
    expect($request->bodyStringList('missing'))->toBe([]);
});

test('getCookie returns cookie value', function () {
    $request = new Request();

    $ref = new ReflectionClass($request);
    $prop = $ref->getProperty('cookies');
    $prop->setValue($request, ['session_id' => 'abc123']);

    expect($request->getCookie('session_id'))->toBe('abc123');
    expect($request->getCookie('missing'))->toBeNull();
    expect($request->getCookie('missing', 'default'))->toBe('default');
});

test('isSecure detects secure requests from standard server values', function () {
    $request = new Request();

    $ref = new ReflectionClass($request);
    $prop = $ref->getProperty('server');
    $prop->setValue($request, ['HTTPS' => 'on']);

    expect($request->isSecure())->toBeTrue();

    $prop->setValue($request, ['REQUEST_SCHEME' => 'https']);

    expect($request->isSecure())->toBeTrue();
});

test('isSecure detects secure requests behind a proxy', function () {
    $request = new Request();

    $ref = new ReflectionClass($request);
    $prop = $ref->getProperty('server');
    $prop->setValue($request, ['HTTP_X_FORWARDED_PROTO' => 'https, http']);

    expect($request->isSecure())->toBeTrue();
});
