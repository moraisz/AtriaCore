# Architecture

## Package Boundary

Atria is split into two Composer packages:

- **Atria Core** (`moraisz/atria-core`) is the reusable runtime library. Its public
  PHP namespace is `Atria\` and its source lives in `src/`.
- **Atria** (`moraisz/atria`) is the reference application and integration project.
  Its application code uses the `App\` namespace.

Core code must never import `App\` classes or depend on application files. A feature
belongs in the Core only when it is reusable, has a clear API, and can be tested without
application-specific behavior. Application wiring, example routes, Docker configuration,
and user-facing recipes belong in Atria.

## Repository Layout

```text
AtriaCore/
├── bin/          Composer CLI entry point
├── docs/         Contributor documentation
├── extensions/   Go sources for FrankenPHP extensions
├── resources/    Package resources and stubs
├── src/          Atria\ runtime source code
└── tests/        Pest unit and feature tests
```

The main namespaces in `src/` are:

| Namespace | Responsibility |
| --- | --- |
| `Atria\System` | Application bootstrap, configuration, service container, and worker runtime. |
| `Atria\Http` | Requests, responses, routes, controllers, middleware, and exception handling. |
| `Atria\Database` | Database contracts, PostgreSQL implementation, models, query builder, and migrations. |
| `Atria\Modules` | Optional framework services such as Auth, CSRF, Mercure, View, and Vite. |
| `Atria\Helpers` | Small shared utilities. |

Within a subsystem, `Contracts` contains public interfaces and `AbstractClasses` contains
base classes with shared behavior. Keep this distinction when adding new APIs.

## Dependency Direction

The runtime is assembled from the outside in:

```text
Application config
        |
        v
System\App -> System\Config -> System\Container
        |                         |
        v                         v
HTTP router ----------------> Modules and database services
```

`App` creates the `Container`, `Router`, and `Config`. `Config` reads application-owned
configuration files and registers framework services. The router resolves controllers and
middleware through the container at dispatch time.

Framework modules may depend on lower-level System, HTTP, or Database abstractions. The
System layer must not depend on an application. Avoid circular dependencies between
modules; inject a contract or a focused service instead.

## Extension Points

Applications extend Core through configuration and public contracts:

- Register bindings, singletons, and request-scoped services in `config/container.php`.
- Register route classes in `config/routes.php`.
- Implement HTTP controllers from `Atria\Http\AbstractClasses\Controller` and middleware
  from `Atria\Http\AbstractClasses\Middleware`.
- Depend on database interfaces such as `DatabaseConnection` and `QueryBuilder` rather
  than a concrete driver when the abstraction is sufficient.
- Implement `Atria\System\Contracts\Resettable` for long-lived services that retain
  request-specific state.

When a new extension point is needed, define the contract in Core, cover it with tests,
and demonstrate its use in Atria. Do not add an application-specific convenience API to
the reusable runtime.

## Public API Changes

Changes to classes under `src/` can affect downstream applications. Before changing a
public constructor, method, configuration key, or namespace, identify every consumer in
Core, Atria, tests, and documentation. Prefer additive changes when possible. Breaking
changes require explicit migration guidance and an appropriate release version.
