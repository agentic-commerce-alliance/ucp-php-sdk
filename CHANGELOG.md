# Changelog

## Unreleased

### Added

- signing-key commands (`ucp:signing-keys:generate` / `list` / `show-public`) now accept an optional `--tenant` identifier and route to the tenant-aware repository when one is provided; without it they behave exactly as before (global scope) (#73)
- new `ucp:signing-keys:retire` and `ucp:signing-keys:delete` commands complete the key lifecycle (both tenant-aware) (#73)
- the signing-key commands are no longer `final`/`@internal` and expose a `resolveTenantIdentifier()` extension point, so integrators (e.g. the Shopware plugin) can map a domain-specific option to the tenant instead of shipping their own commands (#73)
- enforce UCP request-time negotiation so requests are validated against the negotiated protocol version and capabilities (#65)
- runtime capability enablement, allowing capabilities to be toggled on at runtime rather than only at build time (#50)
- improved remote profile validation for discovered/remote UCP profiles (#47)
- additional validation schemas covering previously unschema'd payloads (#42)
- improved JSON parsing error handling with clearer failure reporting (#45)
- storage smoke tests to guard the default storage adapters (#38)

### Changed

- lowered the PHP requirement from `^8.2` to `^8.1`; `readonly class` declarations were replaced with individually `readonly`-annotated promoted properties across core, symfony-bundle, and examples (#13)
- removed Symfony dependencies from the framework-free core package (#53)
- adopted `phpseclib` for signing operations
- marked internal-only classes as `@internal` to clarify the public API surface (#52)
- refactored the UCP response envelope and money serialization to be spec-derived (#67)
- improved integrator onboarding documentation and closed review gaps (#72)

### Fixed

- include the negotiated UCP metadata in REST response envelopes (#68)
- make the catalog-product REST binding protocol-conformant and emit the catalog-product capability (#69)
- require the UCP agent header on incoming requests (#66)
- fail on duplicate registry entries instead of silently overwriting (#51)
- fix the OAuth metadata route (#41)
- fix idempotency claim handling, including concurrent idempotency safety (#39)
- fix digest verification result reporting (#49)
- fix A2A update-id validation (#70)
- fix discovery signing keys (#35)
- limit database introspection to SDK-owned tables only (#36)
- fix outbound webhook publishing (#40)
- expose MCP endpoints as metadata-only (#43)
- match the full request origin during origin validation (#44)

### Security

- fail closed when encrypted-storage decryption fails, rather than returning plaintext or empty data (#48)
- mitigate SSRF in outbound/remote profile fetching (#37)

## 0.0.1-alpha1 - 2026-05-28

- first alpha release track for the shared UCP PHP SDK (#1)
- framework-free core package with protocol models, negotiation, signing, idempotency, validation, and adapter contracts (#1)
- Symfony bundle with REST routes, default storage adapters, and signing-key commands (#1)
- example Symfony apps for bootstrap and merchant flows (#1)
- QA tooling for tests, static analysis, dead-code checks, coverage, and mutation reports (#1)
