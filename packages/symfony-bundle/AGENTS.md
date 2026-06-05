# Symfony Bundle Agent Guide

This package is the Symfony transport and default storage layer for the SDK.

## Main Responsibilities

- load bundle configuration
- expose public aliases for stable core service interfaces
- autoconfigure extension contracts as tagged services
- map HTTP requests into SDK DTOs
- build request context and idempotency state
- map responses and exceptions back to HTTP JSON
- provide default storage adapters for SDK state

## Tagged Extension Points

- `CapabilityInterface` -> `ucp_sdk.capability`
- `PaymentHandlerInterface` -> `ucp_sdk.payment_handler`
- `ProfileContributorInterface` -> `ucp_sdk.profile_contributor`
- `ProfileSigningKeyProviderInterface` -> `ucp_sdk.profile_signing_key_provider`
- `CheckoutRequestValidatorInterface` -> `ucp_sdk.checkout_request_validator`
- `CheckoutResponseAugmenterInterface` -> `ucp_sdk.checkout_response_augmenter`
- `PaymentMandateVerifierInterface` -> `ucp_sdk.payment_mandate_verifier`
- `OrderWebhookEnricherInterface` -> `ucp_sdk.order_webhook_enricher`
- `EmbeddedPageRendererInterface` -> `ucp_sdk.embedded_renderer`

Adapter tags reserved for platform packages:

- `ucp_sdk.adapter.catalog`
- `ucp_sdk.adapter.cart`
- `ucp_sdk.adapter.checkout`
- `ucp_sdk.adapter.order`
- `ucp_sdk.adapter.discount`
- `ucp_sdk.adapter.identity_linking`
- `ucp_sdk.adapter.payment`

## Public Service Aliases

These are the main stable bundle-level service entry points:

- `RuntimeConfigurationResolverInterface`
- `HttpRequestContextFactoryInterface`
- `CapabilityNegotiatorInterface`
- `ProtocolValidatorInterface`
- `RequestSignatureServiceInterface`
- `MerchantAuthorizationServiceInterface`
- `OrderWebhookPublisherInterface`
- repository interfaces in `Ucp\Sdk\Repository`

## Config Shape

Current config keys:

- `version`
- `base_uri`
- `allowed_profile_hosts`
- `allowed_agent_domains`
- `signature_policy`
- `idempotency_required`
- `idempotency_ttl`
- `max_request_body_bytes`
- `platform_profile_cache_ttl`
- `negotiation_session_ttl`
- `signature_max_lifetime_seconds`
- `oauth.authorization_code_ttl`
- `supported_versions`
- `transports`
- `transport_endpoints`
- `signing_keys.*`
- `idempotency.max_stored_response_bytes`
- `webhooks.timeout`
- `ap2.enabled`
- `storage.dsn`

`signature_policy` is restricted to `off`, `log`, or `strict`.
`transports` is restricted to `rest`, `mcp`, `a2a`, and `embedded`.

## Storage Boundary

The `Bridge/DoctrineDbal` folder is the default Symfony storage adapter for SDK state:

- signing keys
- idempotency records
- OAuth state
- platform-profile cache
- negotiation sessions
- signature replay nonces

Do not describe this layer as the platform model. Shopware should replace storage pieces as needed in its own plugin.

Example repository replacement:

```php
$services->set(ManagedSigningKeyRepositoryInterface::class, ShopwareSigningKeyRepository::class);
```

## Commands

- `ucp:signing-keys:generate`
- `ucp:signing-keys:list`
- `ucp:signing-keys:show-public`
- `ucp:storage:cleanup`
- `ucp:storage:cleanup-signature-nonces`

## QA Boundary

- `Bridge`, `EventListener`, `Command`, and controller runtime code are inside the dead-code scan scope.
- `DependencyInjection`, `UcpSdkBundle.php`, and `UcpSdkConfiguration.php` are treated as config glue and are not part of the internal coverage target band.
- Keep bundle runtime code referenced through real service wiring, not through artificial test-only entrypoints.

## Do Not Do This

- Do not add Shopware entity mapping here.
- Do not add Shopware-specific MCP runtime code here. Generic transport controllers and metadata are allowed when they stay platform-neutral and respect bundle configuration.
- Do not turn `HttpPayloadMapper` into a platform mapper.
- Do not require consumers to subclass controllers to customize commerce behavior.

## Related Docs

- [../../docs/storage-adapters.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/docs/storage-adapters.md)
- [../../docs/mapping-flow.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/docs/mapping-flow.md)
- [../../docs/shopware-plugin-blueprint.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/docs/shopware-plugin-blueprint.md)
- [../../docs/qa-dead-code.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/docs/qa-dead-code.md)
