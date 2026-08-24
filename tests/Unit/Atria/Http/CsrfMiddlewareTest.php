<?php

declare(strict_types=1);

use Atria\Modules\Csrf\Exceptions\CsrfTokenValidationException;
use Atria\Modules\Csrf\Middlewares\CsrfMiddleware;
use Atria\Modules\Csrf\CsrfManager;
use Atria\Http\Request;
use Atria\Http\Response;

test('invalid csrf token throws a csrf validation exception', function () {
    $_SESSION['csrf_token'] = 'expected';
    $request = new Request();
    $reflection = new ReflectionClass($request);
    $reflection->getProperty('method')->setValue($request, 'POST');
    $reflection->getProperty('body')->setValue($request, ['csrf_token' => 'wrong']);

    try {
        (new CsrfMiddleware(new CsrfManager()))->handle($request, new Response(), fn() => new Response());
        test()->fail('Expected CsrfTokenValidationException was not thrown');
    } catch (CsrfTokenValidationException $exception) {
        expect($exception->getStatusCode())->toBe(403);
        expect($exception->getMessage())->toBe('CSRF token validation failed');
    }
});

test('valid csrf token rotates session token and adds it to response header', function () {
    $_SESSION['csrf_token'] = 'expected';
    $request = new Request();
    $reflection = new ReflectionClass($request);
    $reflection->getProperty('method')->setValue($request, 'POST');
    $reflection->getProperty('body')->setValue($request, ['csrf_token' => 'expected']);

    $response = (new CsrfMiddleware(new CsrfManager()))->handle(
        $request,
        new Response(),
        fn(Request $request, Response $response) => $response->json(['ok' => true]),
    );

    $rotatedToken = $response->getHeader('X-CSRF-Token');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('{"ok":true}')
        ->and($rotatedToken)->toBeString()
        ->and($rotatedToken)->not->toBe('expected')
        ->and($_SESSION['csrf_token'])->toBe($rotatedToken);
});

test('CSRF manager stores a 32-byte token in the default session key', function () {
    $manager = new CsrfManager();
    $token = $manager->currentToken();

    expect($token)->toHaveLength(64)
        ->and($_SESSION['csrf_token'])->toBe($token)
        ->and($manager->validateToken($token))->toBeTrue();
});
