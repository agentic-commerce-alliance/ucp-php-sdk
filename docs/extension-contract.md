# Extension Contract

## Stable namespaces

The following namespaces are part of the curated stable surface:

- `Ucp\Sdk\Contract`
- `Ucp\Sdk\Model`
- `Ucp\Sdk\Enum`
- `Ucp\Sdk\Exception`
- `Ucp\Sdk\Event`
- `Ucp\Sdk\Repository`
- selected interfaces in `Ucp\Sdk\Service`
- `Ucp\Sdk\Adapter`
- public bundle entrypoints in `Ucp\Sdk\Symfony`
- `Ucp\Sdk\Symfony\Operation\ShoppingOperationExecutor` and `ShoppingOperationRequest`
- `Ucp\Sdk\Symfony\Bridge\DoctrineDbal\SchemaBootstrapper`

The last two are carve-outs from otherwise-internal namespaces, so they are named
individually rather than by namespace. Anything else under
`Ucp\Sdk\Symfony\Operation` or `Ucp\Sdk\Symfony\Bridge` remains internal.

## Replaceable services

Host applications and platform plugins are expected to replace or decorate
services behind these interfaces:

- `ProfileBuilderInterface`
- `RuntimeConfigurationResolverInterface`
- `HttpRequestContextFactoryInterface`
- `CapabilityNegotiatorInterface`
- `ProtocolValidatorInterface`
- `RequestSignatureServiceInterface`
- `MerchantAuthorizationServiceInterface`
- `SchemaValidatorInterface`
- `AgentProfileFetcherInterface`
- `IdempotencyServiceInterface`
- `OrderWebhookPublisherInterface`
- repository interfaces in `Ucp\Sdk\Repository`

## Internal namespaces

The following namespaces are internal and may change in minor releases:

- `Ucp\Sdk\Internal`
- `Ucp\Sdk\Symfony\Bridge`, except `DoctrineDbal\SchemaBootstrapper`
- `Ucp\Sdk\Symfony\EventListener`
- default Doctrine DBAL repositories

`@internal` is load-bearing rather than advisory here: the backward-compatibility
check skips symbols marked with it, so a signature change to an internal class is
invisible to that gate. Removing the annotation is therefore the act of promotion,
and adding one to something adopters already use silently removes its protection.

## Running an operation from your own transport

`ShoppingOperationExecutor` is how a transport this bundle does not ship reaches the
capability layer with the same guarantees the REST routes get -- negotiation
enforcement, payload mapping, request and response schema validation, and the
response envelope:

```php
$response = $executor->execute(new ShoppingOperationRequest(
    'cart.get',
    payload: [],
    context: $requestContext,
    id: $cartId,
));
```

`$id` exists for operations whose resource identifier arrives from the transport
rather than the payload, so callers do not have to duplicate it into `$payload`.

## Bootstrapping storage

`SchemaBootstrapper::ensureSchema()` creates or updates the tables the Doctrine DBAL
storage adapters need. Adopters call it from wherever their platform installs things,
which is typically outside the request lifecycle -- before the container that would
otherwise provide it exists. It is idempotent and additive, and must stay that way,
because it runs again on every upgrade against storage that already holds data.

## Extension hooks

Stable extension hooks are available through:

- tagged capability registrations
- tagged payment handler registrations
- tagged profile signing-key providers
- profile contributors
- checkout request validators
- checkout response augmenters
- payment mandate verifiers
- order webhook enrichers
- embedded page renderers
- domain events in `Ucp\Sdk\Event`

## Platform Adapter Boundary

The recommended commerce-platform integration path is:

1. either implement capability contracts directly or implement platform adapter contracts in `Ucp\Sdk\Adapter`
2. if using adapters, return public SDK DTOs and payload shapes rather than platform entities
3. expose those adapters through adapter-backed capabilities only when you want the convenience wrapper

The shared SDK should not carry Shopware, Sylius, or other platform entity classes in its public surface.
