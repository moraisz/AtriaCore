# Atria Core Developer Documentation

This documentation explains how Atria Core is structured, how its runtime works, and
how to contribute changes safely.

| Document | Purpose |
| --- | --- |
| [01. Architecture](01-architecture.md) | Package boundaries, source layout, dependencies, and extension points. |
| [02. Runtime Lifecycle](02-runtime-lifecycle.md) | Application bootstrap, request handling, container lifetimes, and worker mode. |
| [03. Built-in Modules](03-modules.md) | Responsibilities and configuration of the framework modules. |
| [04. Contributing](04-contributing.md) | Local setup, testing, quality checks, and contribution expectations. |

## Scope

`moraisz/atria-core` is the reusable runtime package. It exposes the `Atria\` namespace
and must not depend on application-specific classes.

The sibling [`Atria`](../../Atria/) repository is the reference application and integration
surface for the Core. It owns application code, project-level Docker setup, and examples
under the `App\` namespace.

For application installation and end-user usage, start with the Atria project. These
documents are intentionally focused on framework maintainers and contributors.
