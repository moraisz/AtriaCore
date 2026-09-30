<?php

declare(strict_types=1);

use Atria\Http\Session;

beforeEach(function () {
    // Session ini settings cannot change while a session left by another test is open.
    new Session()->close();

    $this->originalIni = [
        'session.save_path' => (string) ini_get('session.save_path'),
        'session.use_cookies' => (string) ini_get('session.use_cookies'),
    ];
    $this->savePath = sys_get_temp_dir() . '/atria-session-' . bin2hex(random_bytes(4));
    mkdir($this->savePath);
    ini_set('session.save_path', $this->savePath);
    ini_set('session.use_cookies', '1');
    $_COOKIE = [];
    new Session()->close();
});

afterEach(function () {
    new Session()->close();
    $_COOKIE = [];
    session_id('');

    foreach ($this->originalIni as $key => $value) {
        ini_set($key, $value);
    }

    foreach (glob($this->savePath . '/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($this->savePath);
});

/**
 * Stores the values in a new session, closes it and sends its cookie, like a
 * returning visitor.
 *
 * @param array<string, mixed> $values
 */
function returningVisitor(array $values): string
{
    $session = new Session();
    foreach ($values as $key => $value) {
        $session->put($key, $value);
    }

    $id = session_id();
    $session->close();

    $_COOKIE[session_name()] = $id;
    session_id($id);

    return $id;
}

/**
 * @return list<string>
 */
function sessionFiles(string $savePath): array
{
    return glob($savePath . '/sess_*') ?: [];
}

test('reads without a session cookie never start a session', function () {
    $session = new Session();

    expect($session->get('user', 'guest'))->toBe('guest')
        ->and($session->pull('error'))->toBeNull()
        ->and($session->isStarted())->toBeFalse();

    $session->close();

    expect(sessionFiles($this->savePath))->toBe([]);
});

test('writes start a session even without a cookie', function () {
    $session = new Session();
    $session->put('user', 'ana');

    expect($session->isStarted())->toBeTrue()
        ->and($session->get('user'))->toBe('ana');
});

test('reads of an existing session release its lock right away', function () {
    returningVisitor(['user' => 'ana']);
    $session = new Session();

    expect($session->get('user'))->toBe('ana')
        ->and($session->isStarted())->toBeFalse();
});

test('a write after a read reopens the session and keeps stored values', function () {
    returningVisitor(['user' => 'ana', 'theme' => 'dark']);
    $session = new Session();

    $session->get('user');
    $session->put('theme', 'light');
    $session->close();

    returningVisitor([]);
    $reader = new Session();

    expect($reader->get('user'))->toBe('ana')
        ->and($reader->get('theme'))->toBe('light');
});

test('pull only locks the session when the key exists', function () {
    returningVisitor(['error' => 'Invalid credentials']);
    $session = new Session();

    expect($session->pull('missing', 'none'))->toBe('none')
        ->and($session->isStarted())->toBeFalse()
        ->and($session->pull('error'))->toBe('Invalid credentials')
        ->and($session->isStarted())->toBeTrue();

    $session->close();
    returningVisitor([]);

    expect(new Session()->get('error'))->toBeNull();
});

test('close forgets data loaded read-only', function () {
    returningVisitor(['user' => 'ana']);
    $session = new Session();
    $session->get('user');

    $session->close();
    $_COOKIE = [];

    expect($session->get('user'))->toBeNull()
        ->and($_SESSION)->toBe([]);
});

test('without cookie-based sessions a read uses the id set elsewhere', function () {
    $id = returningVisitor(['user' => 'ana']);
    $_COOKIE = [];
    ini_set('session.use_cookies', '0');
    session_id($id);

    expect(new Session()->get('user'))->toBe('ana');
});
