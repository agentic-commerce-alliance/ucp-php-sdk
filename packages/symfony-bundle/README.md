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
    'profile_fetching_development_mode' => false,
    'signature_policy' => 'log',
    'transports' => ['rest', 'a2a', 'embedded'],
    'enabled_capabilities' => [
        'dev.ucp.shopping.catalog',
        'dev.ucp.shopping.cart',
    ],
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
`enabled_capabilities` is an allowlist of capability descriptor names; the default empty list keeps every registered capability enabled.
When `mcp` is enabled, `transport_endpoints.mcp` is required because the shared SDK advertises MCP metadata only and does not provide a default `/ucp/mcp` runtime endpoint.
Remote profile fetching is default-deny until `allowed_profile_hosts` is configured. Set `profile_fetching_development_mode` to `true` only for localhost/plain-HTTP development.

## Storage Schema

The default DBAL repositories do not install or migrate tables automatically.
If you use the bundled DBAL storage adapter, call the schema bootstrapper from
your install, update, deployment, or startup lifecycle before handling UCP
traffic:

```php
use Ucp\Sdk\Symfony\Bridge\DoctrineDbal\SchemaBootstrapper;

final readonly class InstallUcpStorage
{
    public function __construct(private SchemaBootstrapper $bootstrapper)
    {
    }

    public function __invoke(): void
    {
        $this->bootstrapper->ensureSchema();
    }
}
```

`SchemaBootstrapper::ensureSchema()` is idempotent and updates only SDK-owned
tables. It leaves unrelated application tables untouched.

Replace the default storage adapter by binding repository interfaces to your own services.

The default DBAL-backed repositories are only storage adapters for SDK state. They are not the required integration model for Shopware or any other platform.

The bundle runtime classes are part of the dead-code QA scope. Coverage and internal reference checks focus on bridge code, listeners, commands, controllers, and the realistic merchant example instead of the exported SDK contracts.

For service tags, config shape, and replacement rules, see [AGENTS.md](AGENTS.md).
