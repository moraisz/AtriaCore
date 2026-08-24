<?php

declare(strict_types=1);

namespace Atria\System;

use Atria\Database\Contracts\DatabaseConnection;
use Atria\Database\Contracts\QueryBuilder;
use Atria\Database\AbstractClasses\Model;
use Atria\Database\Drivers;
use Atria\Database\Migrator;
use Atria\Http\Router;
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

class Config
{
    private string $configPath;
    private string $containerConfigPath;
    private string $databaseConfigPath;
    private string $frankenConfigPath;
    private string $mercureConfigPath;
    private string $routesConfigPath;
    private string $authConfigPath;
    private string $viewConfigPath;
    private string $viteConfigPath;
    /** @var array<string, mixed> */
    private array $authConfig;
    /** @var array<string, mixed> */
    private array $frankenConfig;
    private MercureConfig $mercureConfig;

    public function __construct(string $configPath)
    {
        $this->configPath = rtrim($configPath, '/');
        $this->containerConfigPath = $this->configPath . '/container.php';
        $this->databaseConfigPath = $this->configPath . '/database.php';
        $this->frankenConfigPath = $this->configPath . '/franken.php';
        $this->mercureConfigPath = $this->configPath . '/mercure.php';
        $this->routesConfigPath = $this->configPath . '/routes.php';
        $this->authConfigPath = $this->configPath . '/auth.php';
        $this->viewConfigPath = $this->configPath . '/view.php';
        $this->viteConfigPath = $this->configPath . '/vite.php';
    }

    public function configureApp(Container $container, Router $router): void
    {
        $this->configureContainer($container);
        $this->configureAuthConfig();
        $this->configureDatabase($container);
        $this->configureFranken();
        $this->configureMercure($container);
        $this->configureCsrf($container);
        $this->configureVite($container);
        $this->configureView($container);
        $this->configureAuth($container);
        $this->configureRoutes($router);
    }

    public function configureCli(Container $container): void
    {
        $this->configureContainer($container);
        $this->configureAuthConfig();

        $this->configureDatabase($container, true);
        $this->configureCsrf($container);
        $this->configureVite($container);
        $this->configureView($container);
        $this->configureMercure($container);
        $this->configureAuth($container);
    }

    private function configureContainer(Container $container): void
    {
        /** @var array{bindings: array<string, string>, singletons: array<string, string>, scoped?: array<string, string>} $containerConfig */
        $containerConfig = require $this->containerConfigPath;

        foreach ($containerConfig['singletons'] as $interface => $implementation) {
            $container->singleton($interface, $implementation);
        }

        foreach ($containerConfig['bindings'] as $interface => $implementation) {
            $container->bind($interface, $implementation);
        }

        foreach ($containerConfig['scoped'] ?? [] as $interface => $implementation) {
            $container->scoped($interface, $implementation);
        }
    }

    private function configureDatabase(Container $container, bool $migrator = false): void
    {
        /** @var array{default: string, connections: array<string, array<string, mixed>>, migrations_path: string, models_path: string} $databaseConfig */
        $databaseConfig = require $this->databaseConfigPath;

        $driver = $databaseConfig['default'];
        $resolved = Drivers::resolve($driver);

        if ($resolved === null) {
            throw new \RuntimeException("Unsupported database driver: {$driver}");
        }

        $connectionConfig = $databaseConfig['connections'][$driver] ?? [];

        $container->singleton(
            DatabaseConnection::class,
            static function () use ($resolved, $connectionConfig): DatabaseConnection {
                $class = $resolved['connection'];
                return new $class($connectionConfig);
            },
        );

        $container->bind(QueryBuilder::class, $resolved['query_builder']);

        Model::setResolver(function () use ($container): QueryBuilder {
            /** @var QueryBuilder $qb */
            $qb = $container->make(QueryBuilder::class);
            return $qb;
        });

        if ($migrator) {
            $migrationPaths = $this->migrationPaths($databaseConfig);

            $container->bind(
                Migrator::class,
                static function () use ($container, $migrationPaths): Migrator {
                    /** @var QueryBuilder $queryBuilder */
                    $queryBuilder = $container->make(QueryBuilder::class);

                    return new Migrator($queryBuilder, $migrationPaths);
                },
            );
        }
    }

    private function configureAuthConfig(): void
    {
        /** @var array<string, mixed> $authConfig */
        $authConfig = require $this->authConfigPath;

        $this->authConfig = $authConfig;
    }

    private function configureAuth(Container $container): void
    {
        $authConfig = $this->buildAuthConfig();

        if (!$authConfig->isEnabled()) {
            return;
        }

        if (!$container->has(AuthConfig::class)) {
            $container->singleton(AuthConfig::class, fn(): AuthConfig => $authConfig);
        }

        if (!$container->has(AuthTokenService::class)) {
            $container->singleton(
                AuthTokenService::class,
                fn(): AuthTokenService => new AuthTokenService(
                    $authConfig->secret,
                    $authConfig->accessTtl,
                    $authConfig->refreshTtl,
                ),
            );
        }

        if (!$container->has(AuthManager::class)) {
            $container->singleton(
                AuthManager::class,
                function () use ($container): AuthManager {
                    $tokenService = $container->make(AuthTokenService::class);
                    $authConfig = $container->make(AuthConfig::class);

                    if (!$tokenService instanceof AuthTokenService) {
                        throw new \RuntimeException('Invalid auth token service binding.');
                    }

                    if (!$authConfig instanceof AuthConfig) {
                        throw new \RuntimeException('Invalid auth config binding.');
                    }

                    return new AuthManager(
                        static function () use ($container): QueryBuilder {
                            $queryBuilder = $container->make(QueryBuilder::class);

                            if (!$queryBuilder instanceof QueryBuilder) {
                                throw new \RuntimeException('Invalid query builder binding for auth manager.');
                            }

                            return $queryBuilder;
                        },
                        $tokenService,
                        $authConfig,
                    );
                },
            );
        }
    }

    private function configureFranken(): void
    {
        /** @var array<string, mixed> $frankenConfig */
        $frankenConfig = require $this->frankenConfigPath;

        $this->frankenConfig = $frankenConfig;
    }

    private function configureMercure(Container $container): void
    {
        /** @var array<string, mixed> $mercureConfig */
        $mercureConfig = require $this->mercureConfigPath;

        $this->mercureConfig = MercureConfig::fromArray($mercureConfig);

        $container->singleton(MercureConfig::class, fn(): MercureConfig => $this->mercureConfig);
        $container->singleton(
            MercureManager::class,
            fn() => new MercureManager($this->mercureConfig),
        );

        $container->singleton(
            MercurePublisher::class,
            function (): MercurePublisher {
                return new MercurePublisher($this->mercureConfig);
            },
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getFrankenConfig(): array
    {
        return $this->frankenConfig;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAuthConfig(): array
    {
        return $this->authConfig;
    }

    private function configureRoutes(Router $router): void
    {
        /** @var array<int, class-string> $routesConfig */
        $routesConfig = require $this->routesConfigPath;

        foreach ($routesConfig as $routerClass) {
            $routerClass::register($router);
        }
    }

    private function configureCsrf(Container $container): void
    {
        $container->singleton(CsrfManager::class, fn(): CsrfManager => new CsrfManager());
    }

    private function configureView(Container $container): void
    {
        /** @var array<string, mixed> $viewConfig */
        $viewConfig = require $this->viewConfigPath;
        $config = ViewConfig::fromArray($viewConfig);

        $container->singleton(ViewConfig::class, fn(): ViewConfig => $config);
        $container->scoped(
            ViewManager::class,
            fn() => new ViewManager(
                $config,
                $this->requireService($container, CsrfManager::class),
                $this->requireService($container, ViteManager::class),
            ),
        );
    }

    private function configureVite(Container $container): void
    {
        /** @var array<string, mixed> $viteConfig */
        $viteConfig = require $this->viteConfigPath;

        $config = ViteConfig::fromArray($viteConfig);

        $container->singleton(ViteConfig::class, fn(): ViteConfig => $config);
        $container->singleton(ViteManager::class, fn(): ViteManager => new ViteManager($config));
    }

    /**
     * @template T of object
     * @param class-string<T> $service
     * @return T
     */
    private function requireService(Container $container, string $service): object
    {
        $instance = $container->make($service);

        if (!$instance instanceof $service) {
            throw new \RuntimeException("Invalid {$service} binding.");
        }

        return $instance;
    }

    /**
     * @param array{migrations_path: string}|array{migrations_paths: array<int, string>} $databaseConfig
     * @return array<int, string>
     */
    private function migrationPaths(array $databaseConfig): array
    {
        $pathsValue = $databaseConfig['migrations_paths'] ?? null;
        $singlePath = $databaseConfig['migrations_path'] ?? null;
        $paths = is_array($pathsValue)
            ? array_values(array_filter($pathsValue, 'is_string'))
            : (is_string($singlePath) ? [$singlePath] : []);

        if ($this->buildAuthConfig()->usesStandardMigrations()) {
            $paths[] = __DIR__ . '/../Modules/Auth/Migrations';
        }

        return $paths;
    }

    private function buildAuthConfig(): AuthConfig
    {
        $cookies = is_array($this->authConfig['cookies'] ?? null) ? $this->authConfig['cookies'] : [];
        $tables = is_array($this->authConfig['tables'] ?? null) ? $this->authConfig['tables'] : [];
        $accessTtl = $this->authConfig['access_ttl'] ?? 300;
        $refreshTtl = $this->authConfig['refresh_ttl'] ?? 86400;

        return new AuthConfig(
            driver: is_string($this->authConfig['driver'] ?? null) ? $this->authConfig['driver'] : 'off',
            secret: is_string($this->authConfig['secret'] ?? null) ? $this->authConfig['secret'] : '',
            accessTtl: max(1, is_numeric($accessTtl) ? (int) $accessTtl : 300),
            refreshTtl: max(1, is_numeric($refreshTtl) ? (int) $refreshTtl : 86400),
            accessCookie: is_string($cookies['access'] ?? null) ? $cookies['access'] : 'access_token',
            refreshCookie: is_string($cookies['refresh'] ?? null) ? $cookies['refresh'] : 'refresh_token',
            cookieSecure: (bool) ($cookies['secure'] ?? true),
            cookieSameSite: ucfirst(strtolower(is_string($cookies['same_site'] ?? null) ? $cookies['same_site'] : 'Strict')),
            redirectGuestTo: is_string($this->authConfig['redirect_guest_to'] ?? null) ? $this->authConfig['redirect_guest_to'] : '/login',
            redirectAuthenticatedTo: is_string($this->authConfig['redirect_authenticated_to'] ?? null) ? $this->authConfig['redirect_authenticated_to'] : '/',
            usersTable: is_string($tables['users'] ?? null) ? $tables['users'] : 'users',
            refreshTokensTable: is_string($tables['refresh_tokens'] ?? null) ? $tables['refresh_tokens'] : 'refresh_tokens',
        );
    }
}
