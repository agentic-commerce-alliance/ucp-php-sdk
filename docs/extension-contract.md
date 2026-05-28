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
- `Ucp\Sdk\Adapter\Model`
- public bundle entrypoints in `Ucp\Sdk\Symfony`

## Replaceable services

Host applications and the future Shopware plugin are expected to replace or decorate services behind these interfaces:

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
- `Ucp\Sdk\Symfony\Bridge`
- `Ucp\Sdk\Symfony\EventListener`
- default Doctrine DBAL repositories

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
- domain events in `Ucp\Sdk\Event`

## Platform Adapter Boundary

The recommended commerce-platform integration path is:

1. implement platform adapter contracts in `Ucp\Sdk\Adapter`
2. return normalized records from `Ucp\Sdk\Adapter\Model`
3. expose those adapters through adapter-backed capabilities

The shared SDK should not carry Shopware, Sylius, or other platform entity classes in its public surface.
