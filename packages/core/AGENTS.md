# Core Package Agent Guide

This package defines the reusable SDK surface and the adapter layer future commerce plugins should build on.

## Public Namespaces

- `Ucp\Sdk\Contract`
- `Ucp\Sdk\Model`
- `Ucp\Sdk\Enum`
- `Ucp\Sdk\Exception`
- `Ucp\Sdk\Event`
- `Ucp\Sdk\Repository`
- selected interfaces in `Ucp\Sdk\Service`
- `Ucp\Sdk\Adapter`
- `Ucp\Sdk\Adapter\Model`

## Internal Namespace

- `Ucp\Sdk\Internal`

QA note:

- dead-code and coverage gates apply mainly to `src/Internal`
- do not add fake runtime usages just to satisfy a tool
- if a public contract has no in-repo caller, that is normal for this package

## Main Layout

- `src/Contract`
  capability contracts, payment handlers, validators, enrichers, and profile contributors
- `src/Adapter`
  platform adapter contracts and adapter-backed capability implementations
- `src/Adapter/Model`
  normalized records such as `ProductRecord`, `CartRecord`, `CheckoutRecord`, and `OrderRecord`
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

1. Platform adapter returns normalized records.
2. Adapter-backed capability maps normalized records into public UCP DTOs.
3. Transport layer only sees public DTOs and protocol services.

Example:

```php
$catalogCapability = new AdapterBackedCatalogCapability(
    new CapabilityDescriptor('dev.ucp.shopping.catalog', '2026-04-08', '...', '...'),
    $catalogAdapter,
);
```

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

- [../../docs/platform-adapters.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/docs/platform-adapters.md)
- [../../docs/mapping-flow.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/docs/mapping-flow.md)
- [../../docs/security-model.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/docs/security-model.md)
- [../../docs/qa-dead-code.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/docs/qa-dead-code.md)
- [../symfony-bundle/AGENTS.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/packages/symfony-bundle/AGENTS.md)
