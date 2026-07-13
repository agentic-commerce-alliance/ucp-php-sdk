# Getting Started

This guide takes you from `composer require` to your first working UCP request. It is
the fastest path for a developer who is new to this SDK.

## What is UCP?

UCP (Unified Commerce Protocol) is an open protocol that lets autonomous agents and
external clients discover and use a merchant's commerce capabilities — catalog, cart,
checkout, tokenization, identity linking, and order read — over a standard contract,
instead of every integration being bespoke. This SDK implements the merchant side of
that protocol in PHP.

- Protocol target in this repo: **`2026-04-08`**
- Specification: <https://ucp.dev/specification/overview/>

You expose your commerce backend by implementing **capability contracts** (e.g.
`CatalogCapabilityInterface`); the SDK handles discovery, negotiation, request signing,
idempotency, validation, and transport.

## Requirements

- PHP **8.2+** (core supports `8.1+`, the bundle targets `8.2+`)
- For the Symfony path: Symfony **6.4** or **7.x**
- A PDO-compatible database for default storage (SQLite is fine for local development;
  MySQL/PostgreSQL for production)

## Which path should I take?

| You are building... | Use |
|---|---|
| A Symfony application | **Path A — Symfony bundle** (recommended; does the HTTP wiring for you) |
| A non-Symfony app, or embedding the protocol into another framework | **Path B — framework-free core** (you wire transport and storage yourself) |

A runnable reference for Path A lives in
[`examples/bootstrap-symfony-app`](../examples/bootstrap-symfony-app/README.md).

---

## Path A — Symfony bundle

### 1. Install

```bash
composer require ucp-php-sdk/symfony-bundle:^0.0.1
```

This pulls in `shopware/ucp-php-sdk-core` automatically.

### 2. Register the bundle

```php
// config/bundles.php
return [
    // ...
    Ucp\Sdk\Symfony\UcpSdkBundle::class => ['all' => true],
];
```

### 3. Configure the SDK

```yaml
# config/packages/ucp_sdk.yaml
ucp_sdk:
    base_uri: '%env(UCP_BASE_URI)%'        # public base URL of this service
    allowed_profile_hosts: ['your-host']    # hosts allowed when fetching remote profiles
    signature_policy: 'log'                 # off | log | strict (start with log locally)
    storage:
        dsn: 'sqlite:///%kernel.project_dir%/var/ucp_sdk.sqlite'  # or a MySQL/PostgreSQL DSN
```

Defaults you should know (full list in the [README "Runtime Defaults"](../README.md#runtime-defaults)):

- `transports` defaults to `rest`. `a2a`/`embedded` routes return 404 until explicitly enabled.
- `enabled_capabilities` empty = every registered capability is enabled.

### 4. Bootstrap the storage schema (do not skip this)

The default DBAL repositories **do not** create their schema on construction. Call
`SchemaBootstrapper::ensureSchema()` once during install/deploy/boot, otherwise the first
request fails with "no such table" errors.

```php
use Ucp\Sdk\Symfony\Bridge\DoctrineDbal\SchemaBootstrapper;

/** @var SchemaBootstrapper $bootstrapper */
$bootstrapper = $container->get(SchemaBootstrapper::class);
$bootstrapper->ensureSchema();
```

The bootstrap example does this in its `Kernel::boot()`
([Kernel.php](../examples/bootstrap-symfony-app/src/Kernel.php)). In a real app, prefer a
deploy/migration step.

### 5. Implement one capability

Any service implementing a `*CapabilityInterface` is auto-registered (the bundle tags
`CapabilityInterface` via `registerForAutoconfiguration`), so with `autoconfigure: true`
you just write the class:

```php
<?php

declare(strict_types=1);

namespace App\Ucp;

use Ucp\Sdk\Contract\CatalogCapabilityInterface;
use Ucp\Sdk\Model\Catalog\CatalogLookupRequest;
use Ucp\Sdk\Model\Catalog\CatalogProductRequest;
use Ucp\Sdk\Model\Catalog\CatalogSearchRequest;
use Ucp\Sdk\Model\Catalog\CatalogSearchResponse;
use Ucp\Sdk\Model\Catalog\Product;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\RequestContext;

final class CatalogCapability implements CatalogCapabilityInterface
{
    public function describe(): CapabilityDescriptor
    {
        return new CapabilityDescriptor(
            'dev.ucp.shopping.catalog.search',
            '2026-04-08',
            'https://ucp.dev/specification/overview/',
            'https://ucp.dev/schemas/shopping/catalog-search.json',
        );
    }

    public function search(CatalogSearchRequest $request, RequestContext $context): CatalogSearchResponse
    {
        // Replace with a real query against your catalog.
        return new CatalogSearchResponse([
            new Product('sku-1', 'Demo Product', 19.99),
        ]);
    }

    /** @return list<Product> */
    public function lookup(CatalogLookupRequest $request, RequestContext $context): array
    {
        return array_map(
            static fn (string $id): Product => new Product($id, 'Product ' . $id, 9.99),
            $request->ids,
        );
    }

    public function getProduct(CatalogProductRequest $request, RequestContext $context): Product
    {
        return new Product($request->id, 'Product ' . $request->id, 9.99);
    }
}
```

For more extension points (cart, checkout, tokenization, payment handlers, profile
contributors, webhook enrichers) see [extension-contract.md](extension-contract.md). If you
already have platform services, the adapter layer in [platform-adapters.md](platform-adapters.md)
can wrap them instead of implementing capabilities by hand.

### 6. Run it and make your first request

Discovery document (lists the capabilities you registered):

```bash
curl http://127.0.0.1:8080/.well-known/ucp
```

You should see your `dev.ucp.shopping.catalog.search` capability advertised. From there,
clients call the REST operations under `/ucp/v1/*`.

To see the whole thing wired end-to-end, run the example app:

```bash
docker compose build php
docker compose run --rm php composer install
UCP_BASE_URI=http://127.0.0.1:8080 php -S 127.0.0.1:8080 -t examples/bootstrap-symfony-app/public
curl http://127.0.0.1:8080/.well-known/ucp
```

---

## Path B — framework-free core

Install the core only:

```bash
composer require shopware/ucp-php-sdk-core:^0.0.1
```

The core gives you the protocol models, capability/adapter contracts, and the protocol
services (signing, validation, negotiation, idempotency). It does **not** ship an HTTP
server or storage — you provide those:

1. Implement the capability contracts in `Ucp\Sdk\Contract\*` (same as Path A step 5).
2. Implement the storage `Ucp\Sdk\Repository\*Interface` contracts (or port the Symfony
   bundle's DBAL implementations) — see [storage-adapters.md](storage-adapters.md).
3. Wire request handling: build a `RequestContext`, dispatch to your capabilities, and
   serialize responses. The request/response flow is described in
   [concepts-and-flows.md](concepts-and-flows.md).

The Symfony bundle is the reference implementation of this wiring; read its
`UcpSdkExtension` and controllers to see how the pieces connect.

---

## Common pitfalls

For a fuller list with causes and fixes, see [troubleshooting.md](troubleshooting.md).

- **"no such table" on first request** — you skipped `SchemaBootstrapper::ensureSchema()`
  (Path A step 4) or the equivalent repository schema setup (Path B).
- **Signatures rejected** — `signature_policy: strict` requires an active signing key.
  Start with `log` locally; generate a key before going live
  (`bin/console ucp:signing-keys:generate` in the bundle).
- **A2A / embedded routes return 404** — those transports are off by default; enable them
  in `transports`.
- **Remote profile fetch blocked** — add the host to `allowed_profile_hosts`.

## Where to go next

- [troubleshooting.md](troubleshooting.md) — common failure modes and fixes
- [concepts-and-flows.md](concepts-and-flows.md) — how requests flow through the SDK
- [extension-contract.md](extension-contract.md) — every extension point
- [security-model.md](security-model.md) — signing, nonces, authorization
- [production-operator-checklist.md](production-operator-checklist.md) — before you go live
