# Platform Adapters

This SDK separates protocol work from commerce-platform work through adapter contracts in `packages/core/src/Adapter`.

## Goal

The shared SDK should own UCP orchestration once.

Commerce platforms should only need to map their own services and entities into the public SDK DTOs and payload shapes already defined in `Ucp\Sdk\Model`.

## Main Contracts

- `CatalogAdapterInterface`
- `CartAdapterInterface`
- `CheckoutAdapterInterface`
- `OrderAdapterInterface`
- `IdentityLinkingAdapterInterface`
- `PaymentAdapterInterface`

## Recommended Integration Pattern

1. Implement platform adapters in the host package or plugin.
2. Return public SDK DTOs and payload shapes, never platform entities.
3. Wrap those adapters with the provided adapter-backed capabilities only if you want to keep descriptor wiring separate from the adapter implementation.
4. Register the capabilities with the Symfony bundle using `ucp_sdk.capability`.

Direct capability implementations are still valid. The adapter layer is a convenience pattern, not a mandatory abstraction.

Per-store or sales-channel resolution should live in `RuntimeConfigurationResolverInterface` and in the adapter implementation itself, not in a separate generic store-context contract.

## What The Shared SDK Already Provides

- public adapter contracts
- optional adapter-backed capability implementations
- request context, validation, signing, negotiation, and webhook orchestration

## What A Shopware Plugin Should Provide

- sales-channel aware runtime config resolution
- DAL-backed platform adapters
- payment method mapping
- order event triggers for webhook publishing
- admin UI and operator tooling
- Store API MCP proxy/tool wiring and embedded page rendering when those
  surfaces need Shopware runtime context

See [shopware-plugin-blueprint.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/docs/shopware-plugin-blueprint.md) for the platform-specific breakdown.
