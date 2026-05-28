# UCP PHP SDK Agent Guide

Read this file first before changing the repo.

For release workflow and where release notes belong, also read [docs/release-process.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/docs/release-process.md).

## Fast Path

If the goal is to build or extend a future Shopware plugin, read files in this order:

1. [AGENTS.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/AGENTS.md)
2. [packages/core/AGENTS.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/packages/core/AGENTS.md)
3. [packages/symfony-bundle/AGENTS.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/packages/symfony-bundle/AGENTS.md)
4. [docs/platform-adapters.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/docs/platform-adapters.md)
5. [docs/shopware-plugin-blueprint.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/docs/shopware-plugin-blueprint.md)
6. [docs/mapping-flow.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/docs/mapping-flow.md)
7. [docs/concepts-and-flows.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/docs/concepts-and-flows.md)
8. [docs/repo-layout.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/docs/repo-layout.md)

## Core Decisions

- License for repo-owned Composer packages is `MIT`.
- Current release track is `0.0.1-alpha1`.
- Composer package names are:
  - `shopware/ucp-php-sdk`
  - `shopware/ucp-php-sdk-core`
  - `ucp-php-sdk/symfony-bundle`
- The shared SDK is shop-agnostic. Do not add Shopware classes or Shopware-only concepts to `packages/core` or `packages/symfony-bundle`.
- The example apps are plain Symfony apps. Do not add Shopware code there.
- Protocol target is UCP `2026-04-08`.
- Scope is REST-first and business-side only. MCP, A2A, and embedded transport stay out of this shared SDK.
- Doctrine DBAL is only the default Symfony storage adapter for SDK state. It is not the platform model for Shopware.
- Future Shopware work should be DAL-first in its own plugin.
- The root workspace package is not the main install target for external projects. External consumers should require `shopware/ucp-php-sdk-core` or `ucp-php-sdk/symfony-bundle`.
- Top-level `ucp` is reserved for the protocol envelope in normal API responses. Do not put business payload data under that key.
- Discovery is a raw profile document and is not wrapped by the normal success envelope.
- Default OAuth state is security-sensitive SDK state. Codes are hashed, refresh tokens are encrypted, and cleanup is expected.
- Default webhook publishing requires an active signing key already present in storage. Do not reintroduce lazy key generation on the publish path.
- SDK-local canonical JSON lives behind `DeterministicJsonInterface`.

## Mapping Layers

Keep the three mapping layers separate:

1. Protocol mapping
   Lives in `packages/symfony-bundle`.
   Converts HTTP JSON into SDK DTOs and SDK DTOs back into HTTP JSON.
2. Platform mapping
   Lives behind adapter interfaces in `packages/core`.
   Converts commerce-platform objects and services into normalized SDK records.
3. Storage mapping
   Lives behind repository interfaces.
   Persists SDK infrastructure state such as signing keys, OAuth state, idempotency, replay nonces, profile cache, and negotiation sessions.

Default cleanup entrypoint:

- `docker compose run --rm php php bin/console ucp:storage:cleanup`

Do not use `HttpPayloadMapper` as a platform mapper.

## Stable And Internal Areas

Stable surface:

- `Ucp\Sdk\Contract`
- `Ucp\Sdk\Model`
- `Ucp\Sdk\Enum`
- `Ucp\Sdk\Exception`
- `Ucp\Sdk\Event`
- `Ucp\Sdk\Repository`
- selected interfaces in `Ucp\Sdk\Service`
- public Symfony bundle entrypoints under `Ucp\Sdk\Symfony`

Internal surface:

- `Ucp\Sdk\Internal`
- `Ucp\Sdk\Symfony\Bridge`
- `Ucp\Sdk\Symfony\EventListener`
- default DBAL-backed repository implementations

## Copyable Examples

Example Shopware catalog adapter shape:

```php
final readonly class ShopwareCatalogAdapter implements CatalogAdapterInterface
{
    public function __construct(
        private ProductRoute $productRoute,
    ) {
    }

    public function getProduct(string $id, RequestContext $context): ProductRecord
    {
        $product = $this->productRoute->load($id);

        return new ProductRecord(
            $product->getId(),
            $product->getTranslation('name'),
            (float) $product->getCalculatedPrice()->getUnitPrice(),
            'EUR',
        );
    }
}
```

Example Shopware checkout adapter shape:

```php
final readonly class ShopwareCheckoutAdapter implements CheckoutAdapterInterface
{
    public function createCheckout(CheckoutCreateRequest $request, RequestContext $context): CheckoutRecord
    {
        // 1. Resolve sales channel context
        // 2. Map line items into a Shopware cart
        // 3. Price it through Shopware
        // 4. Return normalized CheckoutRecord
    }
}
```

Example runtime config resolver:

```php
final readonly class ShopwareRuntimeConfigurationResolver implements RuntimeConfigurationResolverInterface
{
    public function resolve(HttpRequest $request): RuntimeConfiguration
    {
        $host = parse_url($request->absoluteUri, PHP_URL_HOST) ?: '';

        return new RuntimeConfiguration(
            '2026-04-08',
            'https://' . $host,
            SignaturePolicy::Strict,
            true,
            [$host],
            [$host],
            ['2026-04-08' => 'https://' . $host . '/.well-known/ucp'],
        );
    }
}
```

Example storage adapter replacement:

```php
$services->set(ManagedSigningKeyRepositoryInterface::class, ShopwareSigningKeyRepository::class);
$services->set(NegotiationSessionRepositoryInterface::class, ShopwareNegotiationSessionRepository::class);
```

Example service wiring for adapter-backed capabilities:

```php
$services->set(ShopwareCatalogAdapter::class);
$services->set(AdapterBackedCatalogCapability::class)
    ->args([new CapabilityDescriptor('dev.ucp.shopping.catalog', '2026-04-08', '...', '...'), service(ShopwareCatalogAdapter::class)])
    ->tag('ucp_sdk.capability');
```

## Do Not Do This

- Do not put Shopware entity classes into `packages/core`.
- Do not add MCP runtime concerns to the shared SDK.
- Do not turn the default DBAL storage adapter into the required persistence model for all adopters.
- Do not subclass bundle controllers to customize commerce behavior when an adapter, tagged service, or decorator can do it.

## Folder Guides

- [packages/AGENTS.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/packages/AGENTS.md)
- [packages/core/AGENTS.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/packages/core/AGENTS.md)
- [packages/symfony-bundle/AGENTS.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/packages/symfony-bundle/AGENTS.md)
- [examples/AGENTS.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/examples/AGENTS.md)
- [examples/bootstrap-symfony-app/AGENTS.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/examples/bootstrap-symfony-app/AGENTS.md)
- [examples/merchant-symfony-app/AGENTS.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/examples/merchant-symfony-app/AGENTS.md)
- [docs/AGENTS.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/docs/AGENTS.md)

## QA

- Main QA entrypoint: `docker compose run --rm php composer qa`
- Tests: `docker compose run --rm php composer test`
- Static analysis: `docker compose run --rm php composer phpstan`
- Style: `docker compose run --rm php composer cs:check`
- Metrics: `docker compose run --rm php composer metrics:pdepend`
- Public API snapshot: `docker compose run --rm php composer public-api:check`
- Coverage: `docker compose run --rm php composer coverage`
- Internal coverage summary: `docker compose run --rm php composer coverage:internal`
- Coverage hard gate: `docker compose run --rm php composer coverage:gate`
- Dead code gate: `docker compose run --rm php composer dead-code`
- Unused dependencies: `docker compose run --rm php composer unused-deps`
- Mutation report and gate: `docker compose run --rm php composer mutation`
- Local diff-based mutation run: `docker compose run --rm php composer mutation:changed`
- Explicit mutation gate target: `docker compose run --rm php composer mutation:gate`
- Full broad mutation run: `docker compose run --rm php composer mutation:full`

Dead-code rules:

- Do not treat public SDK namespaces as dead just because the repo does not use them directly.
- Hard gates focus on `Ucp\Sdk\Internal`, bundle bridge and listener code, runtime commands and controllers, and the realistic merchant example.
- The allowlist for internal reference scanning lives in [tools/internal-class-allowlist.php](/Users/b.meyer/Documents/Projects/ucp-php-sdk/tools/internal-class-allowlist.php).
- The default mutation gate is intentionally fast. It targets protocol-critical classes first instead of the wider runtime tree while keeping a hard floor of `79%` MSI and `79%` covered MSI.
- For local work, prefer `composer mutation:changed`. It uses Infection's git-diff filtering against `origin/main` by default and narrows execution to changed files plus related tests.
- Use `composer mutation:full` for the slower broader manual sweep when changing wide parts of the runtime.
