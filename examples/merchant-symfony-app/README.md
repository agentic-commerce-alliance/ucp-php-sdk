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
php -S 127.0.0.1:8081 -t examples/merchant-symfony-app/public \
  examples/merchant-symfony-app/public/index.php
```

The trailing `public/index.php` is the router script, and it is required rather than
optional. PHP's built-in server only falls back to a front controller for requests that
map to a directory; `/.well-known/ucp` maps to nothing, so without it the server answers
`404 ... No such file or directory` before the application is ever reached. Every UCP
route is affected, so the command is unusable without it.

3. Check the discovery document and webhook inbox:

```bash
curl http://127.0.0.1:8081/.well-known/ucp
curl http://127.0.0.1:8081/merchant/demo/webhook-inbox
```

## Environment

| Variable | Default | Purpose |
| --- | --- | --- |
| `APP_ENV` | `dev` | Symfony environment. Boot `prod` to exercise this the way a merchant runs it — `dev` enables remote-profile fetching in development mode, which is exactly the affordance that would let a conformance run pass for the wrong reason. |
| `APP_DEBUG` | on in `dev` | Symfony debug flag. |
| `UCP_MERCHANT_BASE_URI` | `http://localhost:8081` | Advertised base URI, and the host allowlisted for profile fetching. Must match the address actually served or discovery advertises endpoints nobody can reach. |
| `UCP_MERCHANT_STATE_DIR` | `<app>/var` | Where the SDK's sqlite file and the JSON collections behind carts, checkouts and orders are kept. Point it at an empty directory for a known starting point. |
| `UCP_SIGNATURE_POLICY` | `log` | `off`, `log` or `strict`. Note that at `log` a run exercises no signature verification at all. |
| `MERCHANT_BRAND_NAME` | `Acme Outdoor` | Brand published in the discovery profile. |
| `MERCHANT_WEBHOOK_TARGET` | `<base>/merchant/demo/webhook-inbox` | Where order webhooks are delivered. |

Two runs given fresh `UCP_MERCHANT_STATE_DIR` values produce byte-identical discovery
documents, which is what makes this usable as a conformance target.

## Local Runtime Files

- `<state dir>/ucp_sdk.sqlite` is local SDK infrastructure state.
- `<state dir>/state/*.json` stores example merchant domain state such as orders, checkouts, and webhook inbox entries.
- The state directory is `var` unless `UCP_MERCHANT_STATE_DIR` says otherwise.
- `var/cache` and `var/log` are local Symfony runtime files.
- The whole `var` directory is ignored by Git for this example app.
- You can safely delete the generated `var` contents; the demo kernel recreates the local SDK schema on boot.

For technical notes about the local helpers and why the state is split this way, see [AGENTS.md](AGENTS.md).
