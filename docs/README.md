# Docs

Cross-cutting notes for the SDK and the platform integrations built on it. Package-level
detail lives in each package's `README.md` and `AGENTS.md`; these documents link there rather
than repeat it.

## Start here

- [getting-started.md](getting-started.md) — from `composer require` to a running discovery endpoint
- [local-testing.md](local-testing.md) — your first UCP request against your own shop, without a second server, and when you do need one
- [troubleshooting.md](troubleshooting.md) — failure modes, their causes and fixes

## Concepts

- [concepts-and-flows.md](concepts-and-flows.md) — how a request flows through the SDK
- [mapping-flow.md](mapping-flow.md) — protocol-to-platform mapping
- [extension-contract.md](extension-contract.md) — every extension point and its stability
- [platform-adapters.md](platform-adapters.md) — wrapping existing platform services
- [storage-adapters.md](storage-adapters.md) — the storage boundary and the default DBAL adapters
- [security-model.md](security-model.md) — signing, nonces, authorization
- [repo-layout.md](repo-layout.md) — where things live in this monorepo

## Operating and releasing

- [production-operator-checklist.md](production-operator-checklist.md) — before you go live
- [conformance.md](conformance.md) — running the upstream conformance suite, and where this SDK stands against it
- [release-process.md](release-process.md) — how a release is prepared and what a GitHub Release must contain
- [qa-dead-code.md](qa-dead-code.md) — the QA gates and the dead-code tooling

## Decisions and history

- [full-ucp-parity-plan.md](full-ucp-parity-plan.md) — transport model and the MCP-proxy decision
- [ucp-2026-08-25-upgrade.md](ucp-2026-08-25-upgrade.md) — the `2026-08-25` bump: spec-gap statement and the backlog that drove it
- [shopware-plugin-blueprint.md](shopware-plugin-blueprint.md) — the original blueprint for the Shopware plugin

For maintenance notes about these documents, see [AGENTS.md](AGENTS.md).
