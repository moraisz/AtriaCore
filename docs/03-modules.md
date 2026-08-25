# Built-in Modules

## HTTP

`Atria\Http` provides `Request`, `Response`, `Router`, controller and middleware base
classes, and exception handling. Routes are registered through classes listed in
`config/routes.php`. The router obtains controllers and middleware from the container,
which allows their constructor dependencies to be resolved consistently.

## Database and Migrations

`Atria\Database` contains the database contracts, query-builder abstractions, models,
and migrator. The `Drivers` registry currently maps only the `pgsql` driver to
`PgSqlConnection` and `PgSqlQueryBuilder`.

Database configuration defines the default connection, connection details, model path,
and migration path or paths. For CLI migrations, `Config` registers `Migrator` and adds
the built-in Auth migrations when standard Auth migrations are enabled.

New drivers should provide implementations of `DatabaseConnection` and `QueryBuilder`,
be registered through `Drivers`, and have focused tests for both configuration and query
behavior.

## Authentication

`Atria\Modules\Auth` is configured from `config/auth.php`. When its driver is enabled,
Core registers `AuthConfig`, `AuthTokenService`, and `AuthManager` as services.

The module supports credential verification, access and refresh JWTs, refresh-token
rotation, logout, and cookie attachment. Refresh-token persistence uses the configured
query builder and table names. The supplied Auth migrations are included only when the
configuration opts into the standard schema.

## CSRF

`CsrfManager` is registered as a singleton. Views use it to produce escaped CSRF tokens,
and `CsrfMiddleware` validates protected requests. Keep token generation and validation
inside this module instead of duplicating session-token handling in controllers.

## Views and Vite

`ViewManager` renders PHP views, layouts, sections, and components. It is scoped because
rendering state is request-specific. It also exposes helpers for escaping, CSRF tokens,
and Vite tags.

`ViteManager` reads the Vite configuration and chooses development mode only when the
configured build directory contains a `hot` file. Otherwise it requires a production
manifest. Tests rendering Vite tags must create a `hot` file or a valid manifest fixture
explicitly.

## Mercure

`MercureConfig`, `MercureManager`, and `MercurePublisher` are registered from
`config/mercure.php`. `Response` can receive the manager from the router to support
Mercure response behavior. Transport failures use the module's dedicated exception.

## CLI and Go Extensions

The Composer binary is `bin/atria`. Its current commands are migration execution and
rollback, plus application-key generation.

`extensions/` contains Go sources used to build FrankenPHP extensions. Package resources
under `resources/` provide supporting stubs and Vite integration assets. Changes in these
areas should be validated through the Atria application's Docker runtime in addition to
the Core test suite.
