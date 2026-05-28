# Shopware Plugin Blueprint

This document describes what a future Shopware plugin should build on top of the shared SDK.

## Shared SDK Responsibilities

Reuse these pieces from the shared SDK:

- public UCP DTOs and contracts
- optional adapter-backed capabilities
- request context, signing, negotiation, idempotency, and validation services
- webhook publisher
- public repository interfaces

## Shopware Plugin Responsibilities

Implement these in the Shopware plugin:

- `RuntimeConfigurationResolverInterface` using sales-channel and domain context
- Shopware-backed platform adapters:
  - catalog
  - cart
  - checkout
  - order
  - identity linking
  - payment
- Shopware-backed repository replacements where needed
- payment method mapping
- order event subscribers that call `OrderWebhookPublisherInterface`
- admin UI, ACL, diagnostics, and operator flows

## Shopware Plugin Shape

```mermaid
flowchart TD
    A["Shopware plugin"] --> B["RuntimeConfigurationResolverInterface"]
    A --> C["CatalogAdapterInterface or direct capability"]
    A --> D["CartAdapterInterface or direct capability"]
    A --> E["CheckoutAdapterInterface or direct capability"]
    A --> F["OrderAdapterInterface or direct capability"]
    A --> G["PaymentAdapterInterface or direct capability"]
    A --> H["Shopware storage adapters"]
    B --> I["Shared SDK"]
    C --> I
    D --> I
    E --> I
    F --> I
    G --> I
    H --> I
```

## Important Boundaries

- Keep Shopware DAL definitions in the plugin, not in the shared SDK.
- Keep MCP out of the shared SDK. If Shopware needs it later, build it in the plugin or another package.
- Keep HTTP protocol mapping in the bundle. Do not rewrite that logic in the plugin unless Shopware needs a different transport.

## Suggested Build Order

1. Runtime config resolver
2. Catalog and cart adapters
3. Checkout and payment adapters
4. Order adapter and webhook event triggers
5. Repository replacements where the default storage adapter is not enough
6. Admin UI and diagnostics
