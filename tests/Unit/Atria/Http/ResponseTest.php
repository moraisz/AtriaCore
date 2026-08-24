<?php

declare(strict_types=1);

use Atria\Http\Response;
use Atria\Http\Request;
use Atria\Modules\Mercure\MercureConfig;
use Atria\Modules\Mercure\MercureManager;

function responseTestRequest(array $server): Request
{
    $request = new Request();
    $reflection = new ReflectionClass($request);
    $reflection->getProperty('server')->setValue($request, $server);

    return $request;
}

function responseMercureManager(array $overrides = []): MercureManager
{
    return new MercureManager(new MercureConfig(
        $overrides['enabled'] ?? true,
        $overrides['hub_url'] ?? '/.well-known/mercure',
        $overrides['subscribe_jwt_key'] ?? 'subscriber-secret',
        $overrides['subscribe_jwt_ttl'] ?? 3600,
        $overrides['authorization_cookie_domain'] ?? '',
    ));
}

test('json sets content type and encodes data', function () {
    $response = new Response();
    $response->json(['key' => 'value']);

    expect($response->getHeader('Content-Type'))->toBe('application/json');
    expect($response->getContent())->toBe('{"key":"value"}');
    expect($response->getStatusCode())->toBe(200);
});

test('json with custom status code', function () {
    $response = new Response();
    $response->json(['error' => 'not found'], 404);

    expect($response->getStatusCode())->toBe(404);
});

test('html sets content type and content', function () {
    $response = new Response();
    $response->html('<h1>Hello</h1>', 201);

    expect($response->getHeader('Content-Type'))->toBe('text/html; charset=utf-8');
    expect($response->getContent())->toBe('<h1>Hello</h1>');
    expect($response->getStatusCode())->toBe(201);
});

test('redirect sets location and default 302', function () {
    $response = new Response();
    $response->redirect('/login');

    expect($response->getHeader('Location'))->toBe('/login');
    expect($response->getStatusCode())->toBe(302);
});

test('redirect with custom status code', function () {
    $response = new Response();
    $response->redirect('/new-url', 301);

    expect($response->getStatusCode())->toBe(301);
});

test('text sets content type and content', function () {
    $response = new Response();
    $response->text('plain text');

    expect($response->getHeader('Content-Type'))->toBe('text/plain; charset=utf-8');
    expect($response->getContent())->toBe('plain text');
});

test('setCookie builds correct cookie header', function () {
    $response = new Response();
    $response->setCookie('token', 'abc123', 0, '/', '', true, true, 'Strict');

    $cookies = $response->getCookies();
    expect($cookies)->toHaveCount(1);
    expect($cookies[0])->toContain('token=abc123');
    expect($cookies[0])->toContain('Secure');
    expect($cookies[0])->toContain('HttpOnly');
    expect($cookies[0])->toContain('SameSite=Strict');
});

test('setCookie with expiration includes Expires', function () {
    $response = new Response();
    $expires = time() + 3600;
    $response->setCookie('session', 'xyz', $expires);

    $cookies = $response->getCookies();
    expect($cookies[0])->toContain('Expires=');
});

test('getStatusText returns correct texts', function () {
    $response = new Response();
    expect($response->getStatusText())->toBe('OK');

    $response->setStatusCode(404);
    expect($response->getStatusText())->toBe('Not Found');

    $response->setStatusCode(500);
    expect($response->getStatusText())->toBe('Internal Server Error');
});

test('getStatusText returns Unknown for unmapped code', function () {
    $response = new Response();
    $response->setStatusCode(999);
    expect($response->getStatusText())->toBe('Unknown');
});

test('setHeader and getHeader', function () {
    $response = new Response();
    $response->setHeader('X-Custom', 'value');

    expect($response->getHeader('X-Custom'))->toBe('value');
    expect($response->getHeader('X-Not-Set'))->toBeNull();
});

test('appendHeader merges values using separator', function () {
    $response = new Response();
    $response->setHeader('Link', '</css>; rel="preload"');
    $response->appendHeader('Link', '</mercure>; rel="mercure"');

    expect($response->getHeader('Link'))->toBe('</css>; rel="preload", </mercure>; rel="mercure"');
});

test('mercure adds discovery link header', function () {
    $response = (new Response())->setMercureManager(responseMercureManager());
    $response->mercure();

    expect($response->getHeader('Link'))->toBe('</.well-known/mercure>; rel="mercure"');
});

test('mercureAuthorization stores mercure authorization cookie scoped to hub path', function () {
    $response = (new Response())->setMercureManager(responseMercureManager());
    $response->mercureAuthorization('users/1', 600);

    $cookies = $response->getCookies();

    expect($cookies)->toHaveCount(1)
        ->and($cookies[0])->toContain('mercureAuthorization=')
        ->and($cookies[0])->toContain('Path=/.well-known/mercure')
        ->and($cookies[0])->toContain('HttpOnly')
        ->and($cookies[0])->toContain('SameSite=Strict');
});

test('mercureAuthorization stores configured cookie domain', function () {
    $response = (new Response())->setMercureManager(responseMercureManager([
        'hub_url' => 'https://hub.example.com/.well-known/mercure',
        'authorization_cookie_domain' => '.example.com',
    ]));
    $response->mercureAuthorization('users/1');

    expect($response->getCookies())->toHaveCount(1)
        ->and($response->getCookies()[0])->toContain('Domain=.example.com')
        ->and($response->getCookies()[0])->toContain('Secure');
});

test('mercureAuthorization marks relative hub cookies as secure on https requests', function () {
    $response = (new Response())
        ->setMercureManager(responseMercureManager())
        ->setRequestContext(responseTestRequest(['HTTPS' => 'on']));
    $response->mercureAuthorization('users/1');

    expect($response->getCookies())->toHaveCount(1)
        ->and($response->getCookies()[0])->toContain('Secure');
});

test('mercureAuthorization keeps relative hub cookies insecure on http requests', function () {
    $response = (new Response())
        ->setMercureManager(responseMercureManager())
        ->setRequestContext(responseTestRequest(['HTTPS' => 'off']));
    $response->mercureAuthorization('users/1');

    expect($response->getCookies())->toHaveCount(1)
        ->and($response->getCookies()[0])->not->toContain('Secure');
});

test('fluent interface returns self', function () {
    $response = new Response();

    expect($response->setStatusCode(201))->toBe($response);
    expect($response->setHeader('X', 'Y'))->toBe($response);
    expect($response->setContent('body'))->toBe($response);
    expect($response->json([]))->toBe($response);
    expect($response->html(''))->toBe($response);
    expect($response->redirect('/'))->toBe($response);
    expect($response->text(''))->toBe($response);
});

test('isSent tracks send state', function () {
    $response = new Response();
    expect($response->isSent())->toBeFalse();
});
