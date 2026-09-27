# Contributing to Atria Core

## Local Setup

Use PHP 8.4 or newer. Install the Core dependencies from this repository:

```bash
composer install
```

Core unit and feature tests run independently. The sibling `Atria` repository is not
required to run this suite, but it is required to validate that a Core change works in a
real application.

The Atria application's `composer.json` consumes the sibling Core checkout through a
Composer path repository with a symlink. Changes in this repository are therefore visible
to Atria immediately after dependencies are installed there.

## Change Workflow

1. Define the reusable API and its configuration or compatibility impact.
2. Add or update focused tests in `tests/` before changing runtime behavior.
3. Implement the smallest change in `src/` that satisfies the contract.
4. Update this documentation when architecture, lifecycle, configuration, or public API
   behavior changes.
5. Add or update the smallest real usage example in the sibling Atria application.
6. Run the quality checks in both repositories.

Keep Core independent of `App\`. If behavior only makes sense for the reference
application, implement it in Atria instead.

## Tests

Tests use Pest. Place isolated behavior in `tests/Unit/` and interactions between runtime
components in `tests/Feature/`. Reuse or add focused fixtures under `tests/Fixtures/`.

Avoid requiring Docker or FrankenPHP when a fake runtime, mock, or fixture can verify the
Core contract. For view tests that invoke Vite helpers, choose the fixture mode explicitly:

- Create a `hot` file for development-mode tags.
- Create a valid manifest for production-mode tags.

Run a focused test while developing:

```bash
./vendor/bin/pest tests/Unit/Atria/System/ContainerTest.php
```

## Required Checks

Run these commands from the Core repository before submitting a change:

```bash
composer validate --no-check-publish
composer audit
composer cs-check
composer phpstan
composer test
```

Use `composer cs-fix` to apply formatting. Do not manually imitate formatter output.
PHPStan runs at its maximum configured level against `src/`.

GitHub Actions runs the same checks for pushes and pull requests. Pull requests also
run GitHub's dependency review, which reports newly introduced vulnerable dependencies.

For a change affecting bootstrapping, configuration, views and Vite, migrations,
FrankenPHP, Mercure, or Go extensions, also run the relevant checks from the sibling
Atria repository. Use its Docker environment when the runtime integration cannot be
covered by Core fixtures.

## API and Documentation Expectations

Public Core APIs are consumed outside this repository. Before moving namespaces or
changing a public signature, search the Core, Atria, tests, and docs for consumers.

Every reusable feature should include:

- A clear public contract or extension point.
- Automated coverage for normal behavior and meaningful edge cases.
- A small integration use in Atria.
- Documentation for configuration, lifecycle implications, and known limits.

Keep pull requests focused. Separate unrelated refactors from behavior changes so that
reviewers can verify the contract and its integration usage clearly.

## Releases

Releases are managed by the `Release Please` GitHub Actions workflow when commits are
merged into `main`. It creates or updates a release pull request with the next version
and generated changelog. Merging that release pull request creates the Git tag and the
GitHub Release.

Use Conventional Commits for every change that should appear in a release:

```text
fix(router): preserve query parameters
feat(auth): add token revocation endpoint
feat!: replace the route registration contract
```

The versioning policy is:

- `fix:` creates a PATCH release (`0.1.0` to `0.1.1`).
- `feat:` creates a MINOR release (`0.1.0` to `0.2.0`).
- `feat!:` or a `BREAKING CHANGE:` footer denotes an incompatible public change.
- Before `1.0.0`, incompatible changes create the next MINOR release (`0.1.0` to
  `0.2.0`). From `1.0.0` onward, they create the next MAJOR release.
- `docs:`, `test:`, `refactor:`, `style:`, and `chore:` do not create a release unless
  they are paired with a releasable change.

Composer derives the installed package version from Git tags; do not add a `version`
field to `composer.json`.

### Packagist

After registering `moraisz/atria-core` on Packagist, connect its GitHub service hook or
GitHub App integration in Packagist. Every tag created by the release workflow will then
be imported automatically. This authorization is configured in the Packagist account and
is intentionally not stored in this repository.
