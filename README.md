# UCP PHP SDK

UCP PHP SDK for Symfony and plain PHP.

This repo contains the shared SDK, a Symfony bundle, two example apps, local Docker tooling, and the docs needed to build future platform integrations on top.

For technical notes and agent-facing examples, start with [AGENTS.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/AGENTS.md).

Project policies:

- contribution workflow: [CONTRIBUTING.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/CONTRIBUTING.md)
- vulnerability reporting: [SECURITY.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/SECURITY.md)

## Packages

- `shopware/ucp-php-sdk` is the root workspace package for this repo.
- `shopware/ucp-php-sdk-core` is the framework-free core package.
- `ucp-php-sdk/symfony-bundle` is the Symfony integration package.

External consumers should install the core package or the Symfony bundle directly. The root package is the workspace package for this monorepo.

More detail:

- [packages/README.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/packages/README.md)
- [packages/core/README.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/packages/core/README.md)
- [packages/symfony-bundle/README.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/packages/symfony-bundle/README.md)

## Main Idea

The shared SDK owns protocol work:

- UCP models and contracts
- request context, signing, idempotency, negotiation, and validation
- adapter-backed capabilities for commerce platforms

The Symfony bundle owns HTTP wiring:

- routes and controllers
- listeners and response envelopes
- default storage adapters based on Doctrine DBAL
- console commands for signing keys
- one aggregate cleanup command for retained SDK state

Commerce platforms should add platform adapters on top. A future Shopware plugin should stay DAL-first and use the shared SDK through adapter contracts. MCP is intentionally out of scope for this shared SDK.

## Quickstart

Build the local toolchain and install dependencies:

```bash
docker compose build php
docker compose run --rm php composer install
```

Run the tests:

```bash
docker compose run --rm php composer test
```

## Alpha Install

Current release track: `0.0.1-alpha1`.

Release organization:

- GitHub Releases are the source of truth for version-specific release notes.
- [docs/release-process.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/docs/release-process.md) describes how releases are prepared and what each GitHub Release should contain.
- Release Drafter keeps an upcoming draft release current on `main`.

Framework-free install:

```bash
composer require shopware/ucp-php-sdk-core:^0.0.1@alpha
```

Symfony install:

```bash
composer require ucp-php-sdk/symfony-bundle:^0.0.1@alpha
```

Run the full QA pipeline:

```bash
docker compose run --rm php composer qa
```

Generate coverage and dead-code reports:

```bash
docker compose run --rm php composer coverage
docker compose run --rm php composer coverage:internal
docker compose run --rm php composer coverage:gate
docker compose run --rm php composer dead-code
docker compose run --rm php composer unused-deps
docker compose run --rm php composer mutation
docker compose run --rm php composer mutation:gate
docker compose run --rm php composer mutation:full
```

`composer qa` now enforces both the internal coverage gate and the fast scoped mutation gate. Use `composer mutation:full` only when you want the slower broad manual run.

## Runtime Defaults

- OAuth authorization codes are single-use and expire after `600` seconds by default.
- Request bodies are capped at `262144` bytes by default.
- Stored idempotent response bodies are capped at `262144` bytes by default.
- `signature_policy` accepts `off`, `log`, or `strict`.
- Webhook publishing requires an existing active signing key. Generate one before sending live webhooks.
- Expired SDK state can be purged with `docker compose run --rm php php bin/console ucp:storage:cleanup`.
- SDK-local canonical JSON is exposed through `DeterministicJsonInterface`.

## Security

- Vulnerability reporting policy: [SECURITY.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/SECURITY.md)

## Example Apps

- [examples/bootstrap-symfony-app/README.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/examples/bootstrap-symfony-app/README.md) is the smallest useful bundle integration.
- [examples/merchant-symfony-app/README.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/examples/merchant-symfony-app/README.md) is the more realistic merchant reference with catalog, checkout, order read, OAuth, tokenization, and webhook flows.

## Architecture Docs

- [docs/concepts-and-flows.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/docs/concepts-and-flows.md)
- [docs/extension-contract.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/docs/extension-contract.md)
- [docs/platform-adapters.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/docs/platform-adapters.md)
- [docs/mapping-flow.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/docs/mapping-flow.md)
- [docs/repo-layout.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/docs/repo-layout.md)
- [docs/release-process.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/docs/release-process.md)
- [docs/storage-adapters.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/docs/storage-adapters.md)
- [docs/security-model.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/docs/security-model.md)
- [docs/shopware-plugin-blueprint.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/docs/shopware-plugin-blueprint.md)
- [docs/qa-dead-code.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/docs/qa-dead-code.md)

## Scope

Current protocol target: UCP `2026-04-08`.

In scope:

- discovery
- catalog
- cart
- checkout
- tokenization
- identity linking
- order read
- outbound order webhooks

Out of scope in this shared SDK:

- MCP
- A2A
- embedded transport
- Shopware admin UI and DAL definitions
- full AP2 credential stack
