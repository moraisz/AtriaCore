<p align="center">
  <img src="docs/images/AtriaLogo.png" alt="Atria PHP Framework" width="720">
</p>

# Atria Core

Atria Core is the PHP runtime behind the Atria framework. It provides HTTP routing,
dependency injection, configuration, PostgreSQL migrations, auth, CSRF, views, Vite,
Mercure, and FrankenPHP Go extension sources.

## Requirements

- PHP 8.4 or newer
- PostgreSQL when using the database module
- FrankenPHP when using worker mode, Mercure publishing, or Go extensions

## Installation

Install the complete framework for a ready-to-run application:

```bash
composer create-project moraisz/atria my-app
```

Applications that integrate the runtime directly must provide a configuration directory:

```php
use Atria\System\App;

$app = new App(__DIR__ . '/../config');
$app->run();
```

## Worker Lifecycle

In FrankenPHP worker mode, the application container remains alive between
requests. Use the appropriate container lifetime for each service:

- `bind()` creates a new instance for every resolution.
- `singleton()` keeps one instance for the lifetime of the worker.
- `scoped()` keeps one instance for the current request and releases it when
  the request ends.

`ViewManager` is scoped by default. Applications can register other scoped
services in `config/container.php`:

```php
return [
    'bindings' => [],
    'singletons' => [],
    'scoped' => [
        RequestContext::class => RequestContext::class,
    ],
];
```

Long-lived services that retain temporary request state may implement
`Atria\System\Contracts\Resettable`. The worker invokes `reset()` after every
request, including requests that terminate with an exception.

## Go Extensions

The Go sources used to build FrankenPHP extensions are distributed in `extensions/`.
The Atria application Dockerfile compiles them from the installed Composer package.

## Development

```bash
composer install
composer cs-check
composer phpstan
composer test
```
