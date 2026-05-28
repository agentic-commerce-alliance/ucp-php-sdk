# Core Package

This package is the framework-free heart of the SDK.

Package name: `shopware/ucp-php-sdk-core`

It contains:

- immutable UCP models and enums
- capability, payment handler, and adapter contracts
- request context, security, negotiation, validation, and webhook service interfaces
- repository interfaces for SDK infrastructure state
- adapter-backed capability implementations for platform integrations
- committed schema artifacts for UCP `2026-04-08`

## How To Use It

Use this package when you want to build UCP support without taking a dependency on Symfony HTTP wiring.

Install:

```bash
composer require shopware/ucp-php-sdk-core:^0.0.1@alpha
```

Recommended integration pattern:

1. Implement platform adapters such as `CatalogAdapterInterface` or `CheckoutAdapterInterface`.
2. Wrap them with the provided adapter-backed capabilities.
3. Expose those capabilities through the Symfony bundle or your own transport layer.

Example:

```php
$capability = new AdapterBackedOrderCapability(
    new CapabilityDescriptor('dev.ucp.shopping.order', '2026-04-08', 'https://ucp.dev/specification/order/', 'https://ucp.dev/schemas/shopping/order.json'),
    $orderAdapter,
);
```

This package must stay shop-agnostic. Do not put Shopware, Sylius, or framework entity classes here.

Use `ManagedSigningKey`, `PublicSigningKey`, and `ManagedSigningKeyRepositoryInterface` for signing-key lifecycle work.

SDK-local canonical JSON is exposed through `DeterministicJsonInterface`.

Internal runtime code under `src/Internal` is covered by the dead-code and coverage QA gates. Public contracts and DTOs are not treated as dead code just because the repo does not instantiate them directly.

For technical structure, stable API notes, and agent examples, see [AGENTS.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/packages/core/AGENTS.md).
