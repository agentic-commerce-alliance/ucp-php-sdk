# Platform Adapters

This SDK separates protocol work from commerce-platform work through adapter contracts in `packages/core/src/Adapter`.

## Goal

The shared SDK should own UCP orchestration once.

Commerce platforms should only need to map their own services and entities into normalized records such as:

- `ProductRecord`
- `CartRecord`
- `CheckoutRecord`
- `OrderRecord`
- `BuyerRecord`
- `FulfillmentRecord`
- `DiscountRecord`

## Main Contracts

- `CatalogAdapterInterface`
- `CartAdapterInterface`
- `CheckoutAdapterInterface`
- `OrderAdapterInterface`
- `IdentityLinkingAdapterInterface`
- `PaymentAdapterInterface`
- `StoreContextResolverInterface`

## Recommended Integration Pattern

1. Implement platform adapters in the host package or plugin.
2. Return normalized adapter records, not platform entities.
3. Wrap those adapters with the provided adapter-backed capabilities.
4. Register the capabilities with the Symfony bundle using `ucp_sdk.capability`.

## What The Shared SDK Already Provides

- public adapter contracts
- normalized adapter models
- adapter-backed capability implementations
- request context, validation, signing, negotiation, and webhook orchestration

## What A Shopware Plugin Should Provide

- sales-channel aware runtime config resolution
- DAL-backed platform adapters
- payment method mapping
- order event triggers for webhook publishing
- admin UI and operator tooling

See [shopware-plugin-blueprint.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/docs/shopware-plugin-blueprint.md) for the platform-specific breakdown.
