# Built-in Modules

## HTTP

`Atria\Http` provides `Request`, `Response`, `Router`, controller and middleware base
classes, and exception handling. Routes are registered through classes listed in
`config/routes.php`. The router obtains controllers and middleware from the container,
which allows their constructor dependencies to be resolved consistently.

`Request` exposes query-string helpers (`queryString()`, `queryOptionalString()`,
`queryBool()`, `queryInt()`, and `queryStringList()`) alongside `getQuery()`.

For `multipart/form-data`, use `file()` for one file and `files()` or `allFiles()`
for repeated or nested fields. Each `UploadedFile` exposes client metadata, its upload
error and size, plus `isValid()`, `moveTo()` and `openStream()`. The framework never
selects a storage location: the application must validate the file and choose a safe,
generated destination name before calling `moveTo()`.

### HTTP Client

`Atria\Http\Client\HttpClient` calls other services over HTTP through `ext-curl`. It is a
singleton, so each worker keeps its connections (keep-alive, TLS sessions) and DNS
results across requests. Inject it where needed:

```php
$response = $http->post('https://api.example.com/orders', [
    'json' => ['sku' => 'A1'],
    'headers' => ['Authorization' => "Bearer {$token}"],
    'timeout' => 5,
]);

if (!$response->ok()) {
    // $response->status, $response->body, $response->header('Retry-After')
}

$order = $response->json();
```

Options are `headers`, `query`, `json`, `form`, `body`, `timeout` (seconds, default 30),
`connect_timeout` (default 10) and `follow_redirects` (default `false`, at most five).
Error statuses (4xx/5xx) are regular responses; only transport failures (DNS,
connection, TLS, timeout) raise `Atria\Http\Client\Exceptions\HttpClientException`,
whose code is the curl error. Only `http` and `https` URLs are accepted, on redirects
too, and header values containing line breaks are rejected.

Outside `Async::run()` tasks a request blocks like any synchronous call. Inside
`Async::concurrently()` requests overlap, so independent calls take as long as the
slowest one:

```php
[$user, $repos] = Async::concurrently(
    fn() => $http->get("https://api.example.com/users/{$id}")->json(),
    fn() => $http->get("https://api.example.com/users/{$id}/repos")->json(),
);
```

## Database and Migrations

`Atria\Database` contains the database contracts, query-builder abstractions, the schema
builder, models, and migrator. The `Drivers` registry maps each driver name to a
connection, a query builder and a schema grammar:

| Driver | Connection | PHP extension |
| --- | --- | --- |
| `pgsql` | `PgSqlConnection` (non-blocking, pooled) | `pgsql` |
| `mysql`, `mariadb` | `MySqlConnection` (non-blocking queries, pooled; `charset` defaults to `utf8mb4`) | `mysqli` |
| `sqlite` | `SqliteConnection` (`database` is a file path or `:memory:`; enables foreign keys) | `sqlite3` (bundled with PHP images) |

Atria Core does not use PDO. Each driver talks to its database through the native PHP
extension and fails at construction with a clear message when the extension is missing.

Dialect differences stay inside the drivers. MySQL has no `RETURNING`: a single-row
insert is read back through `lastInsertId()` and assumes an `id` primary key, a
multi-row insert returns no rows, and an explicit `returning()` throws. MySQL also needs
the table name in `dropIndex()`. `affected()` returns matched rows on every driver, and
`transaction()` commits, rolls back on any exception, or joins an open transaction.
Bindings are sent with their PHP type, so `false`, `null` and integers reach the database
as booleans, NULL and integers.

Migrations describe tables with `$this->schema` instead of dialect SQL:

```php
$this->schema->create('posts', static function (Blueprint $table): void {
    $table->id();
    $table->foreignId('user_id')->references('users')->cascadeOnDelete();
    $table->string('title', 120);
    $table->boolean('published')->default(false);
    $table->timestamps();
    $table->index(['user_id']);
});
```

Columns are `NOT NULL` unless `nullable()`. `$this->queryBuilder->createTable()` still
accepts raw column definitions for dialect-specific needs.

Database configuration defines the default connection, connection details, model path,
and migration path or paths. For CLI migrations, `Config` registers `Migrator` and adds
the built-in Auth migrations when standard Auth migrations are enabled.

The connection is a singleton that stays open across worker requests and is `Resettable`:
after every request, including failed ones, it rolls back any transaction left open and
closes connections once the optional `max_lifetime` (seconds, `0` = never) has passed. A
statement run outside a transaction is retried once on a fresh connection when the server
dropped the old one; inside a transaction the error is rethrown.

`DatabaseConnection::execute()` returns an `Atria\Database\Result` with `rows` and
`affectedRows`. Every driver raises `Atria\Database\Exceptions\QueryException`, a
`RuntimeException` whose `sqlState()` holds the SQLSTATE of the failure (class `08` means
the connection failed). Results keep PHP types: integers, floats, booleans (PostgreSQL)
and NULL come back as such, while `numeric`/`DECIMAL` values stay strings to keep their
precision. Queries always use `?` placeholders.

### Concurrent queries

PostgreSQL and MySQL connections extend `PooledConnection`. Outside `Async::concurrently()` they
behave like one persistent connection. Inside `Async::concurrently()` each task borrows its own
connection from a per-worker pool of up to `pool_size` connections (default 4), opened on
demand and kept across requests, so independent queries run at the same time:

```php
use Atria\Async\Async;

[$orders, $stats] = Async::concurrently(
    fn() => Order::forUser($id),
    fn() => Stats::forUser($id),
);
```

A transaction pins its connection to the task, or the main flow, that opened it, and
`lastInsertId()` reads the connection that ran the caller's last statement. Starting
concurrent queries while the main flow holds a transaction throws a `LogicException`,
since the other connections would not see its uncommitted changes. After each request the
pool rolls back a leaked transaction and closes connections left mid-query.

Size the database for the worst case: `worker threads x pool_size` connections.

- PostgreSQL (`ext-pgsql`) connects and queries without blocking the thread. Queries go
  through `pg_send_query_params` with unnamed statements, which also works behind
  PgBouncer in transaction mode. `?` is rewritten to `$1..$n` outside literals,
  identifiers and comments; `??` is a literal `?`, e.g. for the jsonb `?` operator.
- MySQL (`ext-mysqli`) uses native prepared statements outside `Async::run()` tasks. Inside a
  task, mysqli only supports plain-text asynchronous queries, so values are escaped into
  the SQL with `real_escape_string()` on the connection charset, and a 1 ms timer polls
  the pending connections with `mysqli::poll()`. Connecting is blocking in mysqli; the
  pool keeps connections open across requests to pay that cost once.
- SQLite (`ext-sqlite3`) works on a local file without an asynchronous API: tasks inside
  `Async::concurrently()` run their queries one after another. Writes with `RETURNING` run inside
  a savepoint, because ext-sqlite3 would otherwise apply them twice.

Upgrading to 2.0:

- PDO is gone. Install the `pgsql`, `mysqli` or `sqlite3` extension instead of the
  `pdo_*` one. The `options` connection entry (PDO attributes) no longer exists.
- `DatabaseConnection::execute()` returns `Result` instead of `PDOStatement|bool`: read
  `->rows` and `->affectedRows` instead of calling `fetchAll()` or `rowCount()`.
- Database errors are `QueryException` (a `RuntimeException`) instead of `PDOException`;
  update `catch` blocks and read the SQLSTATE with `sqlState()`.
- `getConnection()` no longer returns `PDO`: PostgreSQL and MySQL return the
  `ConnectionLink` of the caller's open transaction (or null), SQLite its `SQLite3` object.
- `PdoConnection` was removed: custom drivers extend `PooledConnection` with a
  `ConnectionLink`, or implement `DatabaseConnection` directly.
- The PHP session starts lazily: code reading `$_SESSION` directly must go through
  `Atria\Http\Session` (see the runtime lifecycle).

New drivers should provide a connection (a `PooledConnection` with its `ConnectionLink`
when the database supports asynchronous queries), a `SqlQueryBuilder` and a
`SchemaGrammar`, be registered through `Drivers`, and be added to the `drivers` dataset
in `tests/Integration/Database/DriverTest.php` (and to `ConcurrencyTest` when pooled).

Upgrading from the PostgreSQL-only layer: `DatabaseConnection` gained `inTransaction()`
and `lastInsertId()`, `QueryBuilder` gained `affected()` and `transaction()`, and
`Migrator` now takes a `Schema` as its second constructor argument. Migrations that have
already run are not affected.

## Authentication

`Atria\Modules\Auth` is configured from `config/auth.php`. When its driver is enabled,
Core registers `AuthConfig`, `AuthTokenService`, and `AuthManager` as services.

The module supports credential verification, access and refresh JWTs, refresh-token
rotation, logout, and cookie attachment. Refresh-token persistence uses the configured
query builder and table names. The supplied Auth migrations are included only when the
configuration opts into the standard schema.

## CSRF

`CsrfManager` is registered as a singleton and stores its token through
`Atria\Http\Session`, which starts the session only when a token is read or written.
Views use it to produce escaped CSRF tokens,
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
