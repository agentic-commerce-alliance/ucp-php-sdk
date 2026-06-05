# Symfony Bundle

This package exposes the core SDK through Symfony services and HTTP endpoints.

Package name: `ucp-php-sdk/symfony-bundle`

It contains:

- bundle registration and configuration
- routes and controllers for discovery, catalog, cart, checkout, tokenization, OAuth, order read, A2A, and embedded surfaces
- request-context and idempotency listeners
- protocol JSON mapping
- default storage adapters based on Doctrine DBAL
- signing key and storage cleanup console commands

## How To Use It

Use this package when the host app is Symfony and you want the SDK wired into HTTP endpoints quickly.

Install:

```bash
composer require ucp-php-sdk/symfony-bundle:^0.0.1@alpha
```

The default bundle stack gives you:

- runtime config resolution
- request signing and replay protection
- profile building and discovery signing keys
- idempotency handling
- cached remote platform-profile fetches

Basic bundle config:

```php
$container->extension('ucp_sdk', [
    'base_uri' => 'https://merchant.example',
    'allowed_profile_hosts' => ['merchant.example'],
    'allowed_agent_domains' => ['merchant.example'],
    'signature_policy' => 'log',
    'transports' => ['rest', 'a2a', 'embedded'],
    'transport_endpoints' => [
        'a2a' => 'https://merchant.example/ucp/a2a',
        'embedded' => 'https://merchant.example/ucp/embedded',
    ],
    'storage' => [
        'dsn' => 'sqlite:///%kernel.project_dir%/var/ucp_sdk.sqlite',
    ],
]);
```

Valid `signature_policy` values are `off`, `log`, and `strict`.
Valid `transports` values are `rest`, `mcp`, `a2a`, and `embedded`; REST is the default.

Replace the default storage adapter by binding repository interfaces to your own services.

The default DBAL-backed repositories are only storage adapters for SDK state. They are not the required integration model for Shopware or any other platform.

The bundle runtime classes are part of the dead-code QA scope. Coverage and internal reference checks focus on bridge code, listeners, commands, controllers, and the realistic merchant example instead of the exported SDK contracts.

For service tags, config shape, and replacement rules, see [AGENTS.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/packages/symfony-bundle/AGENTS.md).
