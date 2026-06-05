# Mapping Flow

This doc answers one practical question first:

## What Do I Need To Implement?

You have two valid integration styles.

### Option 1. Implement capability contracts directly

Implement `Ucp\Sdk\Contract\*CapabilityInterface` yourself when:

- your project is small
- you do not need a separate adapter layer
- you are happy to keep protocol descriptors and business mapping in the same class

In that mode the SDK sees only your capability classes and public DTOs.

### Option 2. Implement platform adapters and wrap them

Implement `Ucp\Sdk\Adapter\*AdapterInterface` when:

- you want small platform-focused classes
- you want to reuse the SDK's thin capability wrappers
- you want to keep `describe()` metadata separate from platform mapping logic

In that mode:

1. your adapter returns public SDK DTOs or payload shapes
2. an `AdapterBacked*Capability` wrapper adds the capability descriptor
3. the bundle exposes that capability through HTTP

The adapter layer is optional convenience, not a mandatory abstraction.

## What You Usually Do Not Need To Implement

- You do not need to replace `HttpPayloadMapper`.
- You do not need to replace `HttpRequestContextFactoryInterface` unless you have host-specific request policy logic.
- You do not need to replace repository interfaces if the default Symfony DBAL storage is enough.
- You do not need to create a platform-specific storage layer on day one just because the interfaces exist.

## The Three Mapping Layers

## 1. Protocol Mapping

Lives in `packages/symfony-bundle`.

Flow:

1. Symfony request enters a bundle controller.
2. `HttpPayloadMapper` converts HTTP JSON into SDK request DTOs.
3. `HttpRequestContextFactoryInterface` builds a transport-neutral `RequestContext`.
4. Capability handler returns public SDK DTOs.
5. Bundle converts DTOs back into HTTP JSON.

This layer must stay free of Shopware entities, DAL objects, or other platform types.

## 2. Platform Mapping

Lives either in direct capability implementations or behind adapter contracts in `packages/core/src/Adapter`.

Flow with adapters:

1. Platform adapter reads platform services, repositories, or entities.
2. Adapter maps them into public SDK DTOs such as `Product`, `Cart`, `Checkout`, or `OrderView`.
3. Optional adapter-backed capability wrapper exposes the descriptor and forwards to the adapter.

This is where a Shopware plugin such as `SwagAgenticCommerce` should live.

## 3. Storage Mapping

Lives behind repository interfaces.

Flow:

1. SDK service needs infrastructure state.
2. Repository interface abstracts that state.
3. Bundle default implementation stores it with Doctrine DBAL.
4. Platform-specific packages may replace those repositories if needed.

## Namespace Cheat Sheet

- `Ucp\Sdk\Contract`
  Public business capability contracts and extension hooks
- `Ucp\Sdk\Adapter`
  Optional platform adapter contracts plus the thin wrapper capabilities
- `Ucp\Sdk\Model`
  Public DTOs and payload shapes
- `Ucp\Sdk\Repository`
  Replaceable storage contracts for SDK state
- `Ucp\Sdk\Service`
  Replaceable protocol or orchestration services
- `Ucp\Sdk\Internal`
  Default runtime implementations, not extension surface

## Rule Of Thumb

- HTTP JSON belongs in the bundle.
- Platform entities belong in direct capability code or platform adapters, never in public SDK DTOs.
- SDK infrastructure state belongs in repository adapters.
- If a project can implement a capability directly without losing clarity, that is acceptable.
