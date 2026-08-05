# Changelog

## Unreleased

### Added

- `UcpErrorDescriptor`, the transport-agnostic mapping from a throwable to the `type`, `code`, `severity` and HTTP status of a UCP failure, plus `toMessage()` to render it as a spec-conformant error `Message`. A consumer serving UCP over something other than HTTP — an MCP tool, an A2A task — no longer has to reimplement `ExceptionListener`'s mapping, or report every failure as an untyped internal error because it has none ([#111](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/111))
- `PaymentInstrument` keeps the `billing_address` the spec puts on it. `types/payment_instrument.json` defines a `postal_address` there, and it was dropped in mapping, so a conformant billing address never reached an adapter — which for a cart with nothing to ship is the only address UCP offers ([#112](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/112))
- `CheckoutCreateRequest` carries `payment`. `checkout.json` annotates it `create: optional`, but the model had no slot, so an instrument supplied on create was discarded before any adapter saw it ([#112](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/112))

### Fixed

- HTTP error bodies now carry `code` and `severity` on every message. `types/message_error.json` requires both, and only `NegotiationException` and `AgentProfileException` supplied them: a validation, signature, not-found, capability, idempotency or configuration failure answered with `type` and `content` alone, which is not a conformant error message ([#111](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/111))
- `checkout.create` and `checkout.update` read payment from the spec-shaped `{"instruments": [...]}`, preferring the instrument marked `selected`. They read a top-level `handler_id` that the shape does not have, so a conformant payload silently became an instrument with an empty handler id — the failure `checkout.complete` was given a list-aware path to avoid, which these two never got. The flat single-instrument shape still works, and a payment object naming no instrument now yields no instrument instead of one with an empty handler id ([#112](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/112))

## 0.0.3 - 2026-08-04

### Added

- `CheckoutCompleteRequest`, plus the opt-in `PaymentAwareCheckoutCapabilityInterface` and `PaymentAwareCheckoutAdapterInterface`, so `checkout.complete` can read the payment the protocol marks as required for it. Implementations that do not opt in are still called through `completeCheckout()` unchanged ([#107](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/107))
- `Cart` accepts an `extra` array for capability extension fields, matching `Checkout` and `OrderView` ([#107](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/107))
- `AgentProfileException` for agent-profile fetch failures, carrying an `errorCode` of `agent_profile_unreachable`, `agent_profile_unavailable`, `agent_profile_too_large`, or `agent_profile_invalid` ([#108](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/108))
- an advisory PHP 8.5 lane in the test matrix, so forward-compatibility regressions surface without blocking pull requests ([#109](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/109))

### Changed

- the generated `checkout.create` and `checkout.update` request schemas now publish the capability extension fields the SDK already reads — `cart_id`, `discounts.codes`, `fulfillment`, and `buyer.consent`. `required` is unchanged and every new field is optional ([#107](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/107))
- completion payments now pass through the registered mandate verifiers and `PaymentMandateVerificationEvent`, as `checkout.update` already did. A no-op when no verifier is registered or no instrument was named ([#107](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/107))
- `checkout.create` accepts `cart_id` as an alternative to `line_items`, so a cart-to-checkout conversion no longer has to re-send the line items the cart already owns ([#101](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/101))

### Fixed

- `cart.update` merges the resource id into the payload, so callers that supply the id in the route or tool argument no longer get `$.id is required`. A request with no id anywhere now fails as a `BadRequestHttpException` rather than a `ValidationException`, matching every other id-bearing operation ([#107](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/107))
- agent-profile fetch failures are now typed as `UcpException` instead of plain `\RuntimeException`, so a transport failure, non-200 status, oversized response, or undecodable body answers `424` with a diagnosable message and a spec-conformant error message object instead of an opaque `500 Internal server error.`; the failure is also logged with the throwable attached ([#108](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/108))
- the public API snapshot is now identical on every supported PHP version; a return type matching its declaring class renders as `self` on all of them, because PHP 8.5 resolves `self` in reflection while 8.2-8.4 report it literally, which made `composer public-api:check` fail on 8.5 regardless of the change under test ([#109](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/109))
- the PHP container now builds on 8.5; `dom` and `xmlwriter` are no longer reinstalled, since they ship enabled in the official images and rebuilding `dom` fails on 8.5 without liblexbor headers ([#109](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/109))
- `ucp-php-sdk/symfony-bundle` now requires `ucp-php-sdk/core` as `>=0.0.2 <0.1.0` instead of `^0.0.2`. Composer's caret pins the patch on `0.0.x`, so the old constraint excluded every future release of core, including this one

## 0.0.2 - 2026-07-22

### Added

- typed order adjustment models for cancellations, refunds, returns, credits, and disputes ([#89](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/89))

### Changed

- `MonetaryAmount` now converts major to minor units using ISO 4217 minor-unit exponents per currency and normalizes currency codes to uppercase ([#92](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/92))

### Fixed

- restored PHP 8.1 compatibility for the published packages and added a PHP 8.1 source-lint CI lane to prevent regressions ([#94](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/94))
- fixed repository resolution in the tag-triggered draft-release workflow ([#88](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/88))

### Dependencies

- updated the monorepo split action and development tooling ([#81](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/81), [#83](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/83), [#86](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/86), [#91](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/91))

## 0.0.1 - 2026-07-13

### Added

- signing-key commands (`ucp:signing-keys:generate` / `list` / `show-public`) now accept an optional `--tenant` identifier and route to the tenant-aware repository when one is provided; without it they behave exactly as before (global scope) ([#73](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/73))
- new `ucp:signing-keys:retire` and `ucp:signing-keys:delete` commands complete the key lifecycle (both tenant-aware) ([#73](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/73))
- the signing-key commands are no longer `final`/`@internal` and expose a `resolveTenantIdentifier()` extension point, so integrators (e.g. the Shopware plugin) can map a domain-specific option to the tenant instead of shipping their own commands ([#73](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/73))
- `resolveTenantIdentifier()` now receives the command's `OutputInterface`, so an override can prompt interactively or print guidance; an override may throw to abort the command (the exception surfaces as a non-zero exit) ([#76](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/76))
- enforce UCP request-time negotiation so requests are validated against the negotiated protocol version and capabilities ([#65](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/65))
- runtime capability enablement, allowing capabilities to be toggled on at runtime rather than only at build time ([#50](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/50))
- improved remote profile validation for discovered/remote UCP profiles ([#47](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/47))
- additional validation schemas covering previously unschema'd payloads ([#42](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/42))
- improved JSON parsing error handling with clearer failure reporting ([#45](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/45))
- storage smoke tests to guard the default storage adapters ([#38](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/38))

### Changed

- lowered the PHP requirement from `^8.2` to `^8.1`; `readonly class` declarations were replaced with individually `readonly`-annotated promoted properties across core, symfony-bundle, and examples ([#13](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/13))
- removed Symfony dependencies from the framework-free core package ([#53](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/53))
- adopted `phpseclib` for signing operations
- marked internal-only classes as `@internal` to clarify the public API surface ([#52](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/52))
- refactored the UCP response envelope and money serialization to be spec-derived ([#67](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/67))
- improved integrator onboarding documentation and closed review gaps ([#72](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/72))

### Fixed

- include the negotiated UCP metadata in REST response envelopes ([#68](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/68))
- make the catalog-product REST binding protocol-conformant and emit the catalog-product capability ([#69](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/69))
- require the UCP agent header on incoming requests ([#66](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/66))
- fail on duplicate registry entries instead of silently overwriting ([#51](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/51))
- fix the OAuth metadata route ([#41](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/41))
- fix idempotency claim handling, including concurrent idempotency safety ([#39](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/39))
- fix digest verification result reporting ([#49](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/49))
- fix A2A update-id validation ([#70](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/70))
- fix discovery signing keys ([#35](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/35))
- limit database introspection to SDK-owned tables only ([#36](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/36))
- fix outbound webhook publishing ([#40](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/40))
- expose MCP endpoints as metadata-only ([#43](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/43))
- match the full request origin during origin validation ([#44](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/44))

### Security

- fail closed when encrypted-storage decryption fails, rather than returning plaintext or empty data ([#48](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/48))
- mitigate SSRF in outbound/remote profile fetching ([#37](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/37))

## 0.0.1-alpha1 - 2026-05-28

- first alpha release track for the shared UCP PHP SDK ([#1](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/1))
- framework-free core package with protocol models, negotiation, signing, idempotency, validation, and adapter contracts ([#1](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/1))
- Symfony bundle with REST routes, default storage adapters, and signing-key commands ([#1](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/1))
- example Symfony apps for bootstrap and merchant flows ([#1](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/1))
- QA tooling for tests, static analysis, dead-code checks, coverage, and mutation reports ([#1](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/1))
