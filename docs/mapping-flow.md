# Mapping Flow

The SDK uses three mapping layers.

## 1. Protocol Mapping

Lives in `packages/symfony-bundle`.

Flow:

1. Symfony request enters a bundle controller.
2. `HttpPayloadMapper` converts HTTP JSON into SDK request DTOs.
3. `HttpRequestContextFactoryInterface` builds a transport-neutral `RequestContext`.
4. Capability handler returns SDK DTOs.
5. Bundle converts DTOs back into HTTP JSON.

This layer should not know about Shopware entities or DAL objects.

## 2. Platform Mapping

Lives behind adapter contracts in `packages/core`.

Flow:

1. Platform adapter reads platform services, repositories, or entities.
2. Adapter returns normalized records such as `CheckoutRecord` or `OrderRecord`.
3. Adapter-backed capability converts those normalized records into public SDK DTOs.

This layer is where a future Shopware plugin should live.

## 3. Storage Mapping

Lives behind repository interfaces.

Flow:

1. SDK service needs infrastructure state.
2. Repository interface abstracts that state.
3. Bundle default implementation stores it with Doctrine DBAL.
4. Platform-specific packages may replace those repositories.

## Rule Of Thumb

- HTTP JSON belongs in the bundle.
- platform entities belong in platform adapters.
- SDK infrastructure state belongs in repository adapters.
