# UCP PHP SDK Agent Guide

Read this file first before changing the repo.

For release workflow and where release notes belong, also read [docs/release-process.md](docs/release-process.md).

## Fast Path

If the goal touches the reusable SDK, Symfony bundle, transport parity, or
platform adapter model, read files in this order:

1. [AGENTS.md](AGENTS.md)
2. [packages/AGENTS.md](packages/AGENTS.md)
3. [packages/core/AGENTS.md](packages/core/AGENTS.md)
4. [packages/symfony-bundle/AGENTS.md](packages/symfony-bundle/AGENTS.md)
5. [docs/concepts-and-flows.md](docs/concepts-and-flows.md)
6. [docs/mapping-flow.md](docs/mapping-flow.md)
7. [docs/platform-adapters.md](docs/platform-adapters.md)
8. [docs/storage-adapters.md](docs/storage-adapters.md)
9. [docs/security-model.md](docs/security-model.md)
10. [docs/full-ucp-parity-plan.md](docs/full-ucp-parity-plan.md)
11. [docs/repo-layout.md](docs/repo-layout.md)

Read [docs/shopware-plugin-blueprint.md](docs/shopware-plugin-blueprint.md)
only when documenting or checking how a downstream Shopware plugin should
consume the SDK.

## Core Decisions

- License for repo-owned Composer packages is `MIT`.
- Current release track is `0.0.4`.
- Composer package names are:
  - `ucp-php-sdk/monorepo`
  - `ucp-php-sdk/core`
  - `ucp-php-sdk/symfony-bundle`
- The shared SDK is shop-agnostic. Do not add Shopware classes or Shopware-only concepts to `packages/core` or `packages/symfony-bundle`.
- The example apps are plain Symfony apps. Do not add Shopware code there.
- Protocol target is UCP `2026-04-08`.
- Scope now targets full UCP parity at the shared SDK layer: REST, A2A runtime, embedded transport hooks/controllers, and MCP profile metadata are shared SDK concerns when they stay shop-agnostic.
- Doctrine DBAL is only the default Symfony storage adapter for SDK state. It is not the platform model for Shopware.
- Platform-specific work belongs in its own integration package. Shopware-specific work should be DAL-first there.
- The root workspace package is not the main install target for external projects. External consumers should require `ucp-php-sdk/core` or `ucp-php-sdk/symfony-bundle`.
- Top-level `ucp` is reserved for the protocol envelope in normal API responses. Do not put business payload data under that key.
- Discovery is a raw profile document and is not wrapped by the normal success envelope.
- Default OAuth state is security-sensitive SDK state. Codes are hashed, refresh tokens are encrypted, and cleanup is expected.
- Default webhook publishing requires an active signing key already present in storage. Do not reintroduce lazy key generation on the publish path.
- SDK-local canonical JSON lives behind `DeterministicJsonInterface`.
- Keep generic REST, A2A, embedded, and MCP profile support on the shared
  operation/capability layer. Platform-specific proxies, tools, authentication,
  and storage stay in downstream integrations.
- Do not duplicate controllers, transports, or operation executors per commerce
  platform. Use shared code plus explicit runtime configuration.

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

## Platform Boundary Rules

- Customer-facing adapter and gateway flows should use the host platform's
  customer-facing API or service boundaries wherever they exist.
- This is a hard rule for catalog, cart, checkout, customer, identity, payment,
  and order flows in downstream integrations.
- Do not implement buyer-facing behavior with direct persistence access, manual
  customer creation, or hand-rolled context mutation when the platform already
  has a public runtime boundary for that behavior.
- Direct repositories are acceptable for SDK-owned infrastructure state,
  integration-owned configuration, admin/internal metadata, compatibility
  discovery, or a documented exception where no public runtime boundary exists.

## Runtime Transport Rules

- Extra transports must be opt-in through bundle configuration or
  `RuntimeConfigurationResolverInterface`; REST remains the baseline.
- A2A and embedded routes should not expose runtime behavior unless their
  transport is enabled.
- Embedded responses must enforce configured allowed origins and frame ancestor
  policy. Missing or non-allowlisted `Origin` headers should get a controlled
  rejection when origin validation is required.
- When the SDK describes MCP-facing write operations, expose object payload
  schemas such as `payload` plus `id` where needed. Do not model writes as
  JSON-string arguments.

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

Example catalog adapter shape:

```php
final readonly class PlatformCatalogAdapter implements CatalogAdapterInterface
{
    public function __construct(
        private PlatformProductGateway $products,
    ) {
    }

    public function getProduct(string $id, RequestContext $context): Product
    {
        $product = $this->products->load($id);

        return new Product(
            $product->id,
            $product->name,
            $product->price,
            $product->imageUrl,
            ['currency' => $product->currency],
        );
    }
}
```

Example checkout adapter shape:

```php
final readonly class PlatformCheckoutAdapter implements CheckoutAdapterInterface
{
    public function createCheckout(CheckoutCreateRequest $request, RequestContext $context): Checkout
    {
        // 1. Resolve host commerce context
        // 2. Map line items into the platform cart
        // 3. Price it through the platform
        // 4. Return the public Checkout DTO
    }
}
```

Example runtime config resolver:

```php
final readonly class RequestRuntimeConfigurationResolver implements RuntimeConfigurationResolverInterface
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
            [],
            [Transport::Rest, Transport::A2a, Transport::Embedded],
        );
    }
}
```

Example storage adapter replacement:

```php
$services->set(ManagedSigningKeyRepositoryInterface::class, PlatformSigningKeyRepository::class);
$services->set(NegotiationSessionRepositoryInterface::class, PlatformNegotiationSessionRepository::class);
```

Example service wiring for adapter-backed capabilities:

```php
$services->set(PlatformCatalogAdapter::class);
$services->set(AdapterBackedCatalogCapability::class)
    ->args([new CapabilityDescriptor('dev.ucp.shopping.catalog', '2026-04-08', '...', '...'), service(PlatformCatalogAdapter::class)])
    ->tag('ucp_sdk.capability');
```

Projects may also skip the adapter layer and register a direct `CatalogCapabilityInterface` implementation instead.

## Do Not Do This

- Do not put Shopware entity classes into `packages/core`.
- Do not add Shopware-specific MCP runtime code to the shared SDK. Generic transport metadata and generic transport controllers are allowed when they remain shop-agnostic and explicitly configurable.
- Do not turn the default DBAL storage adapter into the required persistence model for all adopters.
- Do not subclass bundle controllers to customize commerce behavior when an adapter, tagged service, or decorator can do it.

## Folder Guides

- [packages/AGENTS.md](packages/AGENTS.md)
- [packages/core/AGENTS.md](packages/core/AGENTS.md)
- [packages/symfony-bundle/AGENTS.md](packages/symfony-bundle/AGENTS.md)
- [examples/AGENTS.md](examples/AGENTS.md)
- [examples/bootstrap-symfony-app/AGENTS.md](examples/bootstrap-symfony-app/AGENTS.md)
- [examples/merchant-symfony-app/AGENTS.md](examples/merchant-symfony-app/AGENTS.md)
- [docs/AGENTS.md](docs/AGENTS.md)

## QA

- Main QA entrypoint: `docker compose run --rm php composer qa`
- Tests: `docker compose run --rm php composer test`
- Static analysis: `docker compose run --rm php composer phpstan`
- Style: `docker compose run --rm php composer cs:check`
- Metrics: `docker compose run --rm php composer metrics:pdepend`
- Public API snapshot: `docker compose run --rm php composer public-api:check`
- Backward compatibility: `docker compose run --rm php composer bc-check`, or
  `sh scripts/bc-check.sh <baseline-ref>` for a specific baseline. CI's `bc` job runs the
  same script from the newest release tag. It compares **refs, not the working tree**, so
  commit before trusting a clean result. Do not call
  `vendor/bin/roave-backward-compatibility-check` at the repo root — Roave analyses the root
  package's sources, this root has no `autoload`, and the direct invocation therefore reports
  nothing on any change (#115).
- Coverage: `docker compose run --rm php composer coverage`
- Internal coverage summary: `docker compose run --rm php composer coverage:internal`
- Coverage hard gate: `docker compose run --rm php composer coverage:gate`
- Dead code gate: `docker compose run --rm php composer dead-code`
- Unused dependencies: `docker compose run --rm php composer unused-deps` (declared but not used)
- **Undeclared** dependencies: `docker compose run --rm php composer require-check` (used but not
  declared — the other direction, and the one that let the bundle ship six commands extending
  `Symfony\Component\Console\Command\Command` without requiring symfony/console, #117). It
  installs each package on its own, because checking at the root would measure the monorepo's
  dev requirements instead of what a consumer gets.
- Mutation report and gate: `docker compose run --rm php composer mutation`
- Local diff-based mutation run: `docker compose run --rm php composer mutation:changed`
- Explicit mutation gate target: `docker compose run --rm php composer mutation:gate`
- Full broad mutation run: `docker compose run --rm php composer mutation:full`

Dead-code rules:

- Do not treat public SDK namespaces as dead just because the repo does not use them directly.
- Hard gates focus on `Ucp\Sdk\Internal`, bundle bridge and listener code, runtime commands and controllers, and the realistic merchant example.
- The allowlist for internal reference scanning lives in [tools/internal-class-allowlist.php](tools/internal-class-allowlist.php).
- The default mutation gate is intentionally fast. It targets protocol-critical classes first instead of the wider runtime tree while keeping a hard floor of `79%` MSI and `79%` covered MSI.
- Local mutation runs default to `7` Infection workers through `MUTATION_THREADS`. CI and release workflows pin `MUTATION_THREADS=4`.
- For local work, prefer `composer mutation:changed`. It uses Infection's git-diff filtering against `origin/main` by default and narrows execution to changed files plus related tests.
- Override the worker count per run with `docker compose run --rm -e MUTATION_THREADS=4 php composer mutation` when needed.
- Use `composer mutation:full` for the slower broader manual sweep when changing wide parts of the runtime.

Test style:

- Write test methods as clear executable examples of the behavior under test.
- Prefer explicit scenario setup over hidden mutation in fixture factories.
- Move stable boilerplate into `setUp()` or `tearDown()` only when concrete
  tests become easier to read.
- When most tests share the same collaborators, configure default mocks in
  `setUp()` and vary only data such as runtime configuration, profiles,
  signature results, request contexts, returned records, or captured calls.
- Expose data knobs on the test class instead of exposing every collaborator
  mock as a property. Keep mock properties only when a test must set a PHPUnit
  expectation on that exact collaborator.
- Keep per-scenario mutations in the test body. The test should make the
  behavior-specific input obvious without chasing a helper.
- Keep test helpers smaller than the code they replace.
- Do not hide mock setup behind factory methods such as `capability()`,
  `validator()`, or `requestContextFactory()` when the mock behavior matters to
  the scenario. Inline it or make it a default in `setUp()`.
- Prefer PHPUnit `createMock()` for interface collaborators. Avoid
  `createStub()` so tests stay explicit about PHPUnit mock semantics.
- Avoid throwaway anonymous classes unless their concrete behavior is the
  subject of the test.
- Use a named fake only when it is smaller and clearer than equivalent mock
  callbacks. Keep it in the test file.
- Prefer one focused test method per distinct exception or behavior over broad
  data providers when each case has its own meaning.
- Use named `yield` cases in unit-test data providers.
- Do not add `#[CoversClass]`, `#[CoversFunction]`, or `#[CoversNothing]` to
  integration tests.

Pull requests:

- Keep PRs focused. Separate test-only refactors, runtime behavior, transport
  work, docs, and release changes unless the user asks otherwise.
- Preserve review history after feedback or CI failures: create a follow-up
  commit unless the user explicitly asks for an amend or force-push.
- PR descriptions should summarize what changed and why. Do not add validation
  sections; CI owns validation reporting.
