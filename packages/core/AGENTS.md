# Core Package Agent Guide

This package defines the reusable SDK surface and the adapter layer host apps
and platform integrations should build on.

## Public Namespaces

- `Ucp\Sdk\Contract`
- `Ucp\Sdk\Model`
- `Ucp\Sdk\Enum`
- `Ucp\Sdk\Exception`
- `Ucp\Sdk\Event`
- `Ucp\Sdk\Repository`
- selected interfaces in `Ucp\Sdk\Service`
- `Ucp\Sdk\Adapter`

## Internal Namespace

- `Ucp\Sdk\Internal`

QA note:

- dead-code and coverage gates apply mainly to `src/Internal`
- do not add fake runtime usages just to satisfy a tool
- if a public contract has no in-repo caller, that is normal for this package
- this package requires only PHP and extensions; keep it that way, and prefer `ext-openssl` over a
  crypto package (`CoreComposerDependencySurfaceTest` enforces it)

## Main Layout

- `src/Contract`
  capability contracts, payment handlers, validators, enrichers, and profile contributors
- `src/Adapter`
  platform adapter contracts and optional adapter-backed capability implementations
- `src/Model`
  immutable DTOs for protocol input and output
- `src/Service`
  stable service interfaces such as runtime config resolution, signature verification, negotiation, and webhook publishing
- `src/Repository`
  replaceable persistence contracts
- `src/Internal`
  default implementations, registries, security helpers, and validators

## Adapter Model

The adapter layer is the recommended integration path for commerce platforms.

Pattern:

1. Platform adapter returns public SDK DTOs and payload shapes, never platform entities.
2. Adapter-backed capability is an optional wrapper when you want to keep descriptor wiring separate from the adapter itself.
3. Transport layer only sees public DTOs and protocol services.

Example:

```php
$catalogCapability = new AdapterBackedCatalogCapability(
    new CapabilityDescriptor('dev.ucp.shopping.catalog', '2026-04-08', '...', '...'),
    $catalogAdapter,
);
```

Projects may skip the adapter layer entirely and implement `CatalogCapabilityInterface`, `CheckoutCapabilityInterface`, or the other capability contracts directly.

## Operation Model

- Keep REST, A2A, embedded, and MCP profile concepts transport-neutral here.
- Put reusable operation execution behind public contracts, DTOs, and services.
- Do not add per-platform operation branches. Platform differences belong in
  adapters or direct capability implementations.
- MCP-facing write operation metadata should use object payload schemas, not
  JSON-string arguments.

## Important Boundaries

- Keep platform objects out of public models.
- Keep Symfony request or response objects out of adapter contracts.
- Keep storage contracts separate from platform adapters.
- Do not move bundle-only transport concerns into this package.

## Security And Protocol Services

Important public service interfaces now live here:

- `HttpRequestContextFactoryInterface`
- `RuntimeConfigurationResolverInterface`
- `RequestSignatureServiceInterface`
- `SignatureReplayGuardInterface`
- `SigningKeyManagerInterface`
- `DeterministicJsonInterface`
- `CapabilityNegotiatorInterface`
- `ProtocolValidatorInterface`
- `OrderWebhookPublisherInterface`
- `MerchantAuthorizationServiceInterface`

These interfaces are the main place where host apps or platform plugins can decorate or replace shared behavior.

## Related Docs

- [../../docs/platform-adapters.md](../../docs/platform-adapters.md)
- [../../docs/mapping-flow.md](../../docs/mapping-flow.md)
- [../../docs/security-model.md](../../docs/security-model.md)
- [../../docs/qa-dead-code.md](../../docs/qa-dead-code.md)
- [../symfony-bundle/AGENTS.md](../symfony-bundle/AGENTS.md)
