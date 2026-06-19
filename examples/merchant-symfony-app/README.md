# Merchant Symfony App

This app is the more realistic Symfony example in the repo.

It shows:

- seeded catalog data
- stored checkout and order state
- pricing with shipping, discount, and tax steps
- OAuth identity linking
- tokenization and payment handler examples
- outbound webhook demo endpoints
- the order-read route backed by merchant state

Use this app when you want to see how the SDK looks inside a small merchant backend before building a real platform plugin.

State split:

- SDK infrastructure state uses the bundle’s default SQLite-backed storage adapter.
- Merchant domain state uses local JSON files under `var/state`.

## Setup

This example uses the root workspace dependencies. It does not have its own `composer.json`.

1. From the repo root, build the PHP image and install dependencies:

```bash
docker compose build php
docker compose run --rm php composer install
```

2. Start the app on the host with PHP `8.2+`:

```bash
UCP_MERCHANT_BASE_URI=http://127.0.0.1:8081 \
MERCHANT_BRAND_NAME="Acme Outdoor" \
MERCHANT_WEBHOOK_TARGET=http://127.0.0.1:8081/merchant/demo/webhook-inbox \
php -S 127.0.0.1:8081 -t examples/merchant-symfony-app/public
```

3. Check the discovery document and webhook inbox:

```bash
curl http://127.0.0.1:8081/.well-known/ucp
curl http://127.0.0.1:8081/merchant/demo/webhook-inbox
```

## Local Runtime Files

- `var/ucp_sdk.sqlite` is local SDK infrastructure state.
- `var/state/*.json` stores example merchant domain state such as orders, checkouts, and webhook inbox entries.
- `var/cache` and `var/log` are local Symfony runtime files.
- The whole `var` directory is ignored by Git for this example app.
- You can safely delete the generated `var` contents; the demo kernel recreates the local SDK schema on boot.

For technical notes about the local helpers and why the state is split this way, see [AGENTS.md](AGENTS.md).
