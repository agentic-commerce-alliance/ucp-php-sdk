# Changelog

## Unreleased

- signing-key commands (`ucp:signing-keys:generate` / `list` / `show-public`) now accept an optional `--tenant` identifier and route to the tenant-aware repository when one is provided; without it they behave exactly as before (global scope)
- new `ucp:signing-keys:retire` and `ucp:signing-keys:delete` commands complete the key lifecycle (both tenant-aware)
- the signing-key commands are no longer `final`/`@internal` and expose a `resolveTenantIdentifier()` extension point, so integrators (e.g. the Shopware plugin) can map a domain-specific option to the tenant instead of shipping their own commands

## 0.0.1-alpha1 - 2026-05-28

- first alpha release track for the shared UCP PHP SDK
- framework-free core package with protocol models, negotiation, signing, idempotency, validation, and adapter contracts
- Symfony bundle with REST routes, default storage adapters, and signing-key commands
- example Symfony apps for bootstrap and merchant flows
- QA tooling for tests, static analysis, dead-code checks, coverage, and mutation reports
