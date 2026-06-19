# UCP PHP SDK

UCP PHP SDK for Symfony and plain PHP.

This repo contains the shared SDK, a Symfony bundle, two example apps, local Docker tooling, and the docs needed to build future platform integrations on top.

For technical notes and agent-facing examples, start with [AGENTS.md](AGENTS.md).

Project policies:

- contribution workflow: [CONTRIBUTING.md](CONTRIBUTING.md)
- vulnerability reporting: [SECURITY.md](SECURITY.md)

## Packages

- `shopware/ucp-php-sdk` is the root workspace package for this repo.
- `shopware/ucp-php-sdk-core` is the framework-free core package.
- `ucp-php-sdk/symfony-bundle` is the Symfony integration package.

External consumers should install the core package or the Symfony bundle directly. The root package is the workspace package for this monorepo.

More detail:

- [packages/README.md](packages/README.md)
- [packages/core/README.md](packages/core/README.md)
- [packages/symfony-bundle/README.md](packages/symfony-bundle/README.md)

## Main Idea

The shared SDK owns protocol work:

- UCP models and contracts
- request context, signing, idempotency, negotiation, and validation
- optional adapter-backed capabilities for commerce platforms

The Symfony bundle owns HTTP wiring:

- routes and controllers
- listeners and response envelopes
- default storage adapters based on Doctrine DBAL
- console commands for signing keys
- one aggregate cleanup command for retained SDK state
- generic A2A and embedded endpoints when those transports are enabled in runtime configuration

Commerce platforms can either implement the capability contracts directly or add platform adapters on top. The adapter-backed wrappers are convenience helpers, not a required layer. A Shopware plugin such as `SwagAgenticCommerce` should stay DAL-first and use the shared SDK through the public contracts. The SDK now keeps full UCP transport parity at the generic layer: REST runtime, A2A runtime, embedded transport hooks/controllers, and MCP profile metadata. Shopware-specific MCP tooling and DAL wiring still belong in the Shopware plugin.

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
- [docs/release-process.md](docs/release-process.md) describes how releases are prepared and what each GitHub Release should contain.
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
docker compose run --rm php composer mutation:changed
docker compose run --rm php composer mutation:gate
docker compose run --rm php composer mutation:full
```

`composer qa` now enforces both the internal coverage gate and the fast scoped mutation gate. Use `composer mutation:changed` for a fast local diff-based mutation run over changed files and related tests, and `composer mutation:full` for the slower broad sweep.

Mutation runs use a fixed local default of `7` Infection workers. Override that per run with `MUTATION_THREADS`, for example `docker compose run --rm -e MUTATION_THREADS=4 php composer mutation:full`. CI and release workflows pin `MUTATION_THREADS=4`.

## Runtime Defaults

- OAuth authorization codes are single-use and expire after `600` seconds by default.
- Request bodies are capped at `262144` bytes by default.
- Stored idempotent response bodies are capped at `262144` bytes by default.
- `signature_policy` accepts `off`, `log`, or `strict`.
- `transports` defaults to `rest`; valid values are `rest`, `mcp`, `a2a`, and `embedded`.
- `transport_endpoints` can override generated profile endpoints per transport.
- `mcp` is metadata-only in the shared SDK and must provide `transport_endpoints.mcp`; the SDK does not generate or handle a default `/ucp/mcp` runtime endpoint.
- Webhook publishing requires an existing active signing key. Generate one before sending live webhooks.
- The default DBAL storage schema is not created from repository constructors. Call `SchemaBootstrapper::ensureSchema()` from your install, update, deployment, or startup lifecycle before using the default repositories.
- Expired SDK state can be purged with `docker compose run --rm php php bin/console ucp:storage:cleanup`.
- SDK-local canonical JSON is exposed through `DeterministicJsonInterface`.

For live deployments and release readiness, use the [production operator checklist](docs/production-operator-checklist.md).

## Security

- Vulnerability reporting policy: [SECURITY.md](SECURITY.md)

## Example Apps

- [examples/bootstrap-symfony-app/README.md](examples/bootstrap-symfony-app/README.md) is the smallest useful bundle integration.
- [examples/merchant-symfony-app/README.md](examples/merchant-symfony-app/README.md) is the more realistic merchant reference with catalog, checkout, order read, OAuth, tokenization, and webhook flows.

## Architecture Docs

- [docs/concepts-and-flows.md](docs/concepts-and-flows.md)
- [docs/extension-contract.md](docs/extension-contract.md)
- [docs/platform-adapters.md](docs/platform-adapters.md)
- [docs/full-ucp-parity-plan.md](docs/full-ucp-parity-plan.md)
- [docs/mapping-flow.md](docs/mapping-flow.md)
- [docs/repo-layout.md](docs/repo-layout.md)
- [docs/release-process.md](docs/release-process.md)
- [docs/production-operator-checklist.md](docs/production-operator-checklist.md)
- [docs/storage-adapters.md](docs/storage-adapters.md)
- [docs/security-model.md](docs/security-model.md)
- [docs/shopware-plugin-blueprint.md](docs/shopware-plugin-blueprint.md)
- [docs/qa-dead-code.md](docs/qa-dead-code.md)

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
- A2A
- embedded transport hooks
- MCP profile metadata when an explicit MCP endpoint is provided by the adopter

Out of scope in this shared SDK:

- Shopware-specific MCP tools and Store API wiring
- Shopware admin UI and DAL definitions
- full AP2 credential stack
