# Examples

This folder contains the Symfony example apps used to show and test the SDK.

- [bootstrap-symfony-app/README.md](bootstrap-symfony-app/README.md) is the small sample for basic wiring.
- [merchant-symfony-app/README.md](merchant-symfony-app/README.md) is the more realistic sample with catalog data, checkout state, OAuth, and webhook demo endpoints.

Both apps are plain Symfony examples. They do not contain Shopware code.

CI coverage:

- `packages/symfony-bundle/tests/Integration/BootstrapSymfonyAppKernelTest.php` boots and exercises the bootstrap example app.
- `packages/symfony-bundle/tests/Integration/MerchantSymfonyAppKernelTest.php` boots and exercises the merchant example app.
- `composer test` runs both in CI.

Setup model:

- The example apps use the root workspace dependencies. They are not standalone Composer projects.
- Run `docker compose build php` and `docker compose run --rm php composer install` once at the repo root before starting either app.
- Runtime files under each app's `var` directory are local only and are ignored by Git.

For technical notes about how these apps are meant to evolve, see [AGENTS.md](AGENTS.md).
