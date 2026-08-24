<?php

declare(strict_types=1);

use Atria\Modules\Auth\AuthConfig;
use Atria\Modules\Auth\AuthManager;
use Atria\Modules\Auth\Services\AuthTokenService;
use Atria\Modules\Mercure\MercureConfig;
use Atria\Modules\Mercure\MercureManager;
use Atria\Modules\Mercure\MercurePublisher;
use Atria\Modules\Csrf\CsrfManager;
use Atria\Modules\Vite\ViteConfig;
use Atria\Modules\Vite\ViteManager;
use Atria\Modules\View\ViewConfig;
use Atria\Modules\View\ViewManager;
use Atria\System\Config;
use Atria\System\Container;

function atriaConfigPath(): string
{
    return dirname(__DIR__, 3) . '/Fixtures/config';
}

beforeEach(function () {
    putenv('APP_KEY=test-secret');
    putenv('AUTH_DRIVER=standard');
    putenv('DB_CONNECTION=pgsql');
    putenv('MERCURE_ENABLED=1');
    putenv('MERCURE_SUBSCRIBER_JWT_KEY=test-mercure-secret');
});

afterEach(function () {
    putenv('APP_KEY');
    putenv('AUTH_DRIVER');
    putenv('DB_CONNECTION');
    putenv('MERCURE_ENABLED');
    putenv('MERCURE_SUBSCRIBER_JWT_KEY');
});

test('configureCli registers auth services when auth driver is active', function () {
    $container = new Container();
    $config = new Config(atriaConfigPath());

    $config->configureCli($container);

    expect($container->has(AuthConfig::class))->toBeTrue();
    expect($container->has(AuthTokenService::class))->toBeTrue();
    expect($container->has(AuthManager::class))->toBeTrue();
    expect($container->make(AuthConfig::class))->toBeInstanceOf(AuthConfig::class);
});

test('configureCli does not register auth services when auth driver is off', function () {
    putenv('AUTH_DRIVER=off');

    $container = new Container();
    $config = new Config(atriaConfigPath());

    $config->configureCli($container);

    expect($container->has(AuthConfig::class))->toBeFalse();
    expect($container->has(AuthTokenService::class))->toBeFalse();
    expect($container->has(AuthManager::class))->toBeFalse();
});

test('configureCli registers typed Mercure services', function () {
    $container = new Container();
    $config = new Config(atriaConfigPath());

    $config->configureCli($container);

    expect($container->has(MercureConfig::class))->toBeTrue();
    expect($container->has(MercureManager::class))->toBeTrue();
    expect($container->has(MercurePublisher::class))->toBeTrue();
    expect($container->make(MercureConfig::class))->toBeInstanceOf(MercureConfig::class);
    expect($container->make(MercureManager::class))->toBeInstanceOf(MercureManager::class);
    expect($container->make(MercurePublisher::class))->toBeInstanceOf(MercurePublisher::class);
});

test('configureCli registers typed CSRF, view, and Vite services', function () {
    $container = new Container();
    new Config(atriaConfigPath())->configureCli($container);

    expect($container->make(CsrfManager::class))->toBeInstanceOf(CsrfManager::class)
        ->and($container->make(ViewConfig::class))->toBeInstanceOf(ViewConfig::class)
        ->and($container->make(ViewConfig::class)->viewsPath)->toBe(atriaConfigPath() . '/../views')
        ->and($container->make(ViewManager::class))->toBeInstanceOf(ViewManager::class)
        ->and($container->make(ViteConfig::class))->toBeInstanceOf(ViteConfig::class)
        ->and($container->make(ViteConfig::class)->basePath)->toBe('/build/')
        ->and($container->make(ViteManager::class))->toBeInstanceOf(ViteManager::class);
});

test('migration paths include core auth migrations only for the standard driver', function () {
    $config = new Config(atriaConfigPath());
    $reflection = new ReflectionClass($config);
    $authConfigProperty = $reflection->getProperty('authConfig');
    $configPath = atriaConfigPath();
    $databaseConfig = require $configPath . '/database.php';
    $migrationPaths = $reflection->getMethod('migrationPaths');

    $authConfigProperty->setValue($config, require $configPath . '/auth.php');
    $standardPaths = $migrationPaths->invoke($config, $databaseConfig);

    $authConfigProperty->setValue($config, [
        'driver' => 'custom',
        'secret' => 'test-secret',
        'access_ttl' => 300,
        'refresh_ttl' => 86400,
        'redirect_guest_to' => '/login',
        'redirect_authenticated_to' => '/',
        'cookies' => [
            'access' => 'access_token',
            'refresh' => 'refresh_token',
            'secure' => true,
            'same_site' => 'Strict',
        ],
        'tables' => [
            'users' => 'users',
            'refresh_tokens' => 'refresh_tokens',
        ],
    ]);
    $customPaths = $migrationPaths->invoke($config, $databaseConfig);

    $authMigrationsPath = realpath(dirname(__DIR__, 4) . '/src/Modules/Auth/Migrations');

    expect(array_map('realpath', $standardPaths))->toContain($authMigrationsPath);
    expect(array_map('realpath', $customPaths))->not->toContain($authMigrationsPath);
});
