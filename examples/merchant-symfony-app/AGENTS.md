# Merchant Symfony App Agent Guide

This app is the richer reference example for the SDK.

## Current Shape

- `src/Kernel.php`
  wires the SDK bundle and the app-local config
- `src/Ucp`
  contains host-side capability implementations and extension hooks
- `src/Support`
  contains merchant-local helpers such as:
  - `ProductCatalog`
  - `JsonStateStore`
  - `PriceCalculator`
  - `MerchantSettings`
  - `UcpModelFactory`
- `src/Controller/WebhookDemoController.php`
  exposes the webhook inbox demo
- `var`
  is runtime-only and ignored by Git

## State Split

- SDK state such as signing keys, OAuth state, idempotency, negotiation sessions, and profile cache uses the bundle’s default SQLite storage adapter.
- Merchant state such as checkouts, orders, and webhook inbox entries uses JSON files under `var/state`.

This split is intentional. It shows the difference between SDK infrastructure storage and platform domain storage.

## External Setup Notes

- This app depends on the root workspace `vendor` directory.
- There is no app-local Composer project here.
- For first run, install dependencies at the repo root, then start `php -S` with `examples/merchant-symfony-app/public` as the document root.
- `var/state` data is disposable example state, not fixture data that should be committed.

## Why This Matters For Platform Integrations

- Platform integrations should keep domain state in their own platform, not in
  the shared SDK.
- The shared SDK should keep owning protocol orchestration and SDK infrastructure concerns.
- This example is close to the target architecture, but still plain Symfony and easy to inspect.

## Related Docs

- [../../docs/platform-adapters.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/docs/platform-adapters.md)
- [../../docs/shopware-plugin-blueprint.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/docs/shopware-plugin-blueprint.md)
