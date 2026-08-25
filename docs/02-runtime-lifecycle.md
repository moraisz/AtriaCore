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

1. Start the PHP session when needed.
2. Build an `Atria\Http\Request` from PHP globals.
3. Match the request method and path against registered routes.
4. Resolve route middleware and the controller through the container.
5. Execute middleware around the route callback.
6. Send the resulting `Atria\Http\Response`.
7. Route any exception through `HttpExceptionHandler`.
8. Close the session and release request-scoped state in a `finally` block.

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
- PHP cycle collection runs before the next request.

Any persistent service that holds request data must either be registered as scoped or
implement `Atria\System\Contracts\Resettable`. This is required to prevent state and
memory from leaking across requests in worker mode.

## Testing Lifecycle Behavior

Worker behavior is tested without requiring FrankenPHP. Feature tests supply a fake
`WorkerRuntime`, set request globals, and invoke the handler repeatedly. Follow this
pattern when adding behavior that differs between a single request and a persistent
worker.
