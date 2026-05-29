# Dead Code And Coverage QA

This repo treats dead-code risk as a QA problem with three separate checks:

1. behavior that is not covered by tests
2. internal classes that are no longer referenced
3. Composer packages that are no longer needed

## Why The Checks Are Scoped

This is an SDK, not just an app.

Public DTOs, contracts, enums, repository interfaces, and selected service interfaces are intentionally exported for future consumers such as a Shopware plugin. They must not be treated as dead code just because this repo does not instantiate every public symbol itself.

That means the hard dead-code gates focus on:

- `packages/core/src/Internal`
- `packages/symfony-bundle/src/Bridge`
- `packages/symfony-bundle/src/EventListener`
- Symfony runtime concrete classes such as commands and controllers
- the realistic merchant example app

## Main Commands

Run the normal QA gate:

```bash
docker compose run --rm php composer qa
```

Generate coverage reports:

```bash
docker compose run --rm php composer coverage
docker compose run --rm php composer coverage:internal
docker compose run --rm php composer coverage:gate
```

Run dead-code specific checks:

```bash
docker compose run --rm php composer dead-code
docker compose run --rm php composer unused-deps
docker compose run --rm php composer mutation
docker compose run --rm php composer mutation:gate
docker compose run --rm php composer mutation:full
```

## Reports

Reports are written to `var/reports/`:

- `coverage/clover.xml`
- `coverage/html/`
- `coverage/internal-summary.json`
- `dead-code/internal-class-references.json`
- `infection/summary.log`
- `infection/index.html`
- `infection-full/summary.log`
- `infection-full/index.html`

## Internal Reference Scan

`tools/check-internal-class-references.php` scans internal and runtime concrete classes and looks for references across `packages/`, `examples/`, and `tools/`.

It exists to catch classes that:

- still compile
- still pass PHPStan
- but are no longer wired through the bundle or called from tests

Intentional exceptions live in `tools/internal-class-allowlist.php`. Every allowlist entry must include:

- a short reason
- an owner

## Coverage Targets

The current hard coverage gate enforces these target bands:

- `packages/core/src/Internal`: `80%`
- `packages/symfony-bundle/src` runtime code: `75%`
- `examples/merchant-symfony-app/src`: `60%`

The bootstrap example is measured for visibility only. It is intentionally thin and not used as a coverage gate.

## Mutation Scope

The default mutation gate is intentionally narrower than the full runtime tree.

`composer mutation` and `composer mutation:gate` focus on the highest-risk protocol code first:

- signatures
- idempotency
- capability negotiation
- webhook dispatch and retry behavior
- remote platform-profile fetch safety
- managed key encryption and signing-key persistence

The default gate keeps runtime reasonable mainly by narrowing the source scope to protocol-critical classes instead of mutating the wider runtime tree on every QA run.

For local work on a branch, prefer:

```bash
docker compose run --rm php composer mutation:changed
```

That command uses Infection's git-diff filtering against `origin/main` by default, mutates only added and modified files, and narrows the initial test run to related tests.

For a deeper manual run across the broader historical scope, use:

```bash
docker compose run --rm php composer mutation:full
```

Local mutation commands default to `7` workers. Override that with `MUTATION_THREADS`, for example `docker compose run --rm -e MUTATION_THREADS=4 php composer mutation`. CI and release workflows pin the broader sweep to `4` workers for stable runtime.

## Mutation Gate

Mutation testing is now part of the hard QA gate.

- `composer mutation` writes the fast scoped Infection reports and fails if the configured threshold is missed
- `composer mutation:changed` is the preferred fast local developer command for branch work
- `composer mutation:gate` is the explicit gate target used by `composer qa`
- `composer mutation:full` is slower and meant for manual deeper inspection, not the default QA gate
- current enforced floor:
  - `MSI`: `79%`
  - `Covered Code MSI`: `79%`

This threshold matches the fast scoped baseline and should only move upward after real test improvements.

## Deferred QA Work

The following QA work is intentionally parked for later.

### Rector For Symfony Code Quality

Rector is a good fit for the Symfony-facing parts of this repo, but it is not in the current hard gate.

Recommended later scope:

- `packages/symfony-bundle/src`
- `packages/symfony-bundle/tests`
- `examples/bootstrap-symfony-app/src`
- `examples/merchant-symfony-app/src`

Recommended starting point:

- add `rector/rector` as a dev dependency
- start with Symfony code-quality rules only
- run it in dry-run mode first
- keep it out of `packages/core/src`
- keep it out of `composer qa` until the first rule set is quiet and predictable

Why it is deferred:

- this repo supports both Symfony `6.4` and `7.x`
- broad Symfony upgrade sets can create noisy diffs
- the framework-free core package should not be shaped by Symfony-specific automation
