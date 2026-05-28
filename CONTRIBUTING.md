# Contributing

This repository uses a Docker-based local workflow. Use the shared PHP container instead of relying on a host PHP setup.

## Local Setup

Build the PHP container:

```bash
docker compose build php
```

Install dependencies:

```bash
docker compose run --rm php composer install
```

## Main Commands

Run the full project test suites:

```bash
docker compose run --rm php composer test
```

Run the full QA gate:

```bash
docker compose run --rm php composer qa
```

Useful targeted commands:

```bash
docker compose run --rm php composer phpstan
docker compose run --rm php composer cs:check
docker compose run --rm php composer coverage:internal
docker compose run --rm php composer dead-code
docker compose run --rm php composer mutation
docker compose run --rm php composer public-api:check
```

Validate package manifests:

```bash
docker compose run --rm php composer validate --strict
docker compose run --rm php composer validate --strict -d packages/core
docker compose run --rm php composer validate --strict -d packages/symfony-bundle
```

## Public API Changes

If you intentionally change the public API surface:

1. regenerate the snapshot:

```bash
docker compose run --rm php composer public-api:dump
```

2. review the generated [tools/public-api-snapshot.txt](/Users/b.meyer/Documents/Projects/ucp-php-sdk/tools/public-api-snapshot.txt)
3. replace [tools/public-api-snapshot.expected.txt](/Users/b.meyer/Documents/Projects/ucp-php-sdk/tools/public-api-snapshot.expected.txt) with the reviewed snapshot
4. rerun:

```bash
docker compose run --rm php composer public-api:check
```

## Working Rules

- Keep `packages/core` framework-free.
- Keep Shopware-specific code out of the shared SDK and example apps.
- Treat `packages/symfony-bundle` as HTTP and Symfony wiring only.
- Keep MCP, A2A, and embedded transport out of this repo.
- Do not commit generated runtime state from `var/` or `examples/**/var/`.

## Pull Requests

Before opening a PR:

1. run `composer qa`
2. make sure intended public API changes are reflected in the snapshot
3. update docs when the public extension model or release process changes

## Releases

Version-specific release notes belong in GitHub Releases, not in a dedicated repo file.

See [docs/release-process.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/docs/release-process.md) for the release workflow and note structure.
