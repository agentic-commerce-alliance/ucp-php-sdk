# Bootstrap Symfony App

This app is the smallest useful bundle integration in the repo.

It shows:

- bundle registration
- simple capability implementations
- profile contribution
- tokenization, checkout, OAuth, and order-read wiring
- local SDK state in the app `var` directory

Use this app when you want to understand the public extension points without extra host-app complexity.

It is intentionally thin. It does not try to model a real merchant backend.

## Setup

This example uses the root workspace dependencies. It does not have its own `composer.json`.

1. From the repo root, build the PHP image and install dependencies:

```bash
docker compose build php
docker compose run --rm php composer install
```

2. Start the app on the host with PHP `8.2+`:

```bash
UCP_BASE_URI=http://127.0.0.1:8080 php -S 127.0.0.1:8080 -t examples/bootstrap-symfony-app/public
```

3. Open the discovery document:

```bash
curl http://127.0.0.1:8080/.well-known/ucp
```

## Local Runtime Files

- `var/ucp_sdk.sqlite` is local SDK infrastructure state.
- `var/cache` and `var/log` are local Symfony runtime files.
- The whole `var` directory is ignored by Git for this example app.
- You can safely delete the generated `var` contents; the demo kernel recreates the local SDK schema on boot.

For technical notes about what stays simple here on purpose, see [AGENTS.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/examples/bootstrap-symfony-app/AGENTS.md).
