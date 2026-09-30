# Runtime Lifecycle

## Bootstrap

An HTTP application starts with `Atria\System\App` and the application's configuration
directory:

```php
$app = new Atria\System\App(__DIR__ . '/../config');
$app->run();
```

`App::run()` creates a `Container` and `Router`, then asks `Config` to configure them.
The configuration loader reads application-owned PHP files for the container, database,
FrankenPHP, Mercure, routes, auth, views, and Vite.

The CLI entry point follows the same configuration model. `bin/atria` creates an `App`
using the current project's `config/` directory and delegates commands to
`App::handleCommand()`.

## HTTP Request Flow

For each request, the runtime follows this sequence:

1. Build an `Atria\Http\Request` from PHP globals.
2. Match the request method and path against registered routes.
3. Resolve route middleware and the controller through the container.
4. Execute middleware around the route callback.
5. Send the resulting `Atria\Http\Response`.
6. Route any exception through `HttpExceptionHandler`.
7. Close the session, if the request opened one, and release request-scoped state in a
   `finally` block.

## Sessions

The PHP session starts lazily. `Atria\Http\Session` opens it on the first `get()`,
`put()`, `pull()` or `forget()`; CSRF tokens and the exception handler's flash message go
through it. Requests that never touch the session create no session file, send no
cookie and take no session lock. Starting the session on every request made each
cookie-less request (APIs, health checks, first visits, and every request over plain HTTP
while `session.cookie_secure` is on) write a new file, and PHP's session GC then scanned
the growing directory: removing it multiplied throughput by 4 to 12 in Atria's
benchmarks.

Reads never hold the session lock. `get()`, and `pull()` of a missing key, return the
default without creating a session when the visitor sends no session cookie (with
`session.use_cookies` on, PHP's default). With a cookie, they load the session with
`read_and_close`, which releases the lock at once, so requests of the same user that only
read run in parallel instead of queueing behind each other. `put()`, `forget()` and
`pull()` of an existing key open the session for writing, re-read the stored data and
hold the lock until the end of the request.

Code must use `Session` instead of reading `$_SESSION` directly: after every request
`Session::close()` writes the session and empties `$_SESSION`, so a worker never exposes
one user's session to the next request.

Route parameters use named placeholders such as `/users/{id}`. A route callback can be a
callable or a `[ControllerClass::class, 'method']` pair. Middleware is executed in the
order declared by the route.

When no route matches, the router returns a JSON 404 response for JSON requests. For
other requests, it renders `pages/errors/404` through `ViewManager`.

## Container Lifetimes

`Atria\System\Container` resolves constructor dependencies through reflection. It supports
three lifetimes:

| Registration | Behavior |
| --- | --- |
| `bind()` | Builds a new instance on every resolution. |
| `singleton()` | Creates one instance for the life of the application container. |
| `scoped()` | Shares an instance during one request, then discards it. |

Use `scoped()` for request state. `ViewManager` uses this lifetime because it stores data,
layout, and section state while rendering. Use `singleton()` only for services that are
safe to keep for the full worker lifetime.

## FrankenPHP Worker Mode

When `config/franken.php` enables `worker_mode`, the same application container handles
multiple requests. `App` delegates the request loop to `WorkerRuntime`, whose production
implementation calls `frankenphp_handle_request()`.

After every request, including an exception path, the container calls
`flushRequestScope()`:

- All request-scoped instances are removed.
- Each resolved singleton or scoped service implementing `Resettable` receives one
  `reset()` call.
- The session is closed and `$_SESSION` emptied.
- PHP cycle collection runs before the next request. Its cost was within benchmark noise,
  so it stays on every request.

Any persistent service that holds request data must either be registered as scoped or
implement `Atria\System\Contracts\Resettable`. This is required to prevent state and
memory from leaking across requests in worker mode.

## Threads and Concurrency

FrankenPHP runs each request on one PHP thread from start to finish; a worker thread only
takes the next request after the handler returns. Throughput across requests therefore
depends on the number of threads, not on Fibers. Atria's Caddyfiles read the thread
settings from the environment (`FRANKENPHP_NUM_THREADS`, `FRANKENPHP_WORKERS`,
`FRANKENPHP_MAX_THREADS`, `FRANKENPHP_MAX_WAIT_TIME`; `0` keeps FrankenPHP's automatic
sizing of two threads per CPU). Requests that wait on I/O scale with more worker threads
than CPUs; CPU-bound requests do not. Every worker thread keeps its own database
connections, so size `FRANKENPHP_WORKERS` against the database's `max_connections`.

Inside one request, independent I/O can overlap with `Atria\Async`:

```php
use Atria\Async\Async;

[$user, $orders] = Async::concurrently(
    fn() => User::findById($id),
    fn() => Order::forUser($id),
);
```

`Atria\Async\EventLoop` is a small loop of Fibers over `stream_select()`, with no
external dependency. There is one loop per PHP thread, registered in the container as a
`Resettable` singleton: after every request, including failed ones, pending callbacks,
timers, stream watchers and suspended tasks are dropped. Code outside `Async::run()` blocks
while waiting, exactly like synchronous code, so the loop is invisible until a request
opts into `Async::concurrently()` or `Async::run()`. Only non-blocking I/O overlaps: the PostgreSQL
and MySQL drivers suspend while waiting for the server, while SQLite runs the tasks one
after another.

## Testing Lifecycle Behavior

Worker behavior is tested without requiring FrankenPHP. Feature tests supply a fake
`WorkerRuntime`, set request globals, and invoke the handler repeatedly. Follow this
pattern when adding behavior that differs between a single request and a persistent
worker.
