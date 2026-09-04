# Changelog

## Unreleased

### Changed (breaking)

- ECDSA signatures now go out in the fixed-width `r || s` form RFC 9421 section 3.3.1 requires — 64 bytes on P-256, 96 on P-384 — instead of the DER that `openssl_sign()` returns. The two are different encodings of the same signature, so every signature this SDK emitted was rejected by every conformant verifier, and every conformant signature it received was rejected in turn: interoperable with nothing but itself. `Signature-Input` also names the algorithm from RFC 9421's HTTP Signature Algorithms registry (`ecdsa-p256-sha256`) rather than by its JWA name (`ES256`), which is what a conformant peer reads. Verification accepts both encodings and both spellings for this release, so a peer still running 0.0.5 keeps working; a peer verifying *our* signatures needs to accept fixed-width, which conformant implementations already do. Signing keys are unaffected and need no migration — storage stays on the JWA name

### Added

- REST responses can be signed per RFC 9421, bound to the request that produced them. Enable with `ucp_sdk.response_signing.enabled: true`. The covered set is `@status`, `"@method";req`, `"@target-uri";req` and — when there is a body — `content-digest`. The `;req` binding is the point: a signature over a response alone says "a business said this", not "a business said this to you, about that", so an intact response would otherwise verify when replayed against a different call. New `Model/Http/HttpResponse` and `Service/ResponseSignatureServiceInterface`; the listener runs after the idempotency listener so a replayed response is signed too, rather than giving the same question two different levels of proof ([#160](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/160))
- Ed25519 signing keys, which the spec recommends and web-bot-auth builds on. `ucp:signing-keys:generate --algorithm=EdDSA` produces an OKP key published as a JWK with `crv: Ed25519` and the public key whole in `x` — RFC 8037 gives an Edwards key no `y`, so the EC coordinate path would have emitted a JWK missing the only member that matters. PHP's openssl extension cannot generate or use these keys at all (`OPENSSL_KEYTYPE_ED25519` is not defined), so generation and signing go through `ext-sodium`, which `packages/core` now requires ([#161](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/161))

### Fixed

- Signature verification rebuilds the signature base from the components the peer said it covered, in the order it listed them. It parsed that list and then discarded it, rebuilding from a fixed `("@method" "@target-uri" "content-digest")` of its own, so any peer covering a different set — which RFC 9421 leaves entirely to the signer — was verified against a base nobody had signed and failed every time, reporting only "signature verification failed". An unknown or parameterised component is now refused rather than skipped, because a base that silently drops a covered component confirms nothing about it; a covered header the request does not carry is named. `@signature-params` is reproduced byte for byte from what was received, so a parameter this SDK does not read (`tag="web-bot-auth"`, today) no longer breaks an otherwise valid signature
- `Content-Digest` is required exactly when the signature covers it, instead of on every request. It is representation metadata (RFC 9530), and a bodyless `GET` has no representation to describe — so a conformant peer sending `GET /ucp/v1/orders/{id}` was rejected, and the SDK's own `GET /ucp/v1/catalog/product/{id}` could never be signed successfully. A request that carries a body whose covered components omit `content-digest` is now rejected: otherwise dropping it from the list would leave the body unattested while the signature still verified
- A successful verification reports whether the content digest was actually checked, rather than always reporting that it was. The flag was hardcoded `true` in the success path — harmless while a digest was unconditionally required, and an overstatement as soon as a bodyless request legitimately has nothing to digest
- `DefaultSigningKeyManager::generate()` rejects an algorithm it cannot generate a key for. It selected the curve with `$algorithm === 'ES384' ? 'secp384r1' : 'prime256v1'`, so anything unrecognised silently produced a P-256 key labelled with whatever was asked for — `generate($kid, 'HS256')` returned a usable-looking key that could never sign, and published a JWK whose `alg` and `crv` disagreed
- Public signing key JWKs now carry `x` and `y` at the full width of the curve, as RFC 7518 section 6.2.1.2 requires. openssl returns EC coordinates as minimal-form integers and `DefaultSigningKeyManager::toPublicKey()` published whatever it was handed, so roughly one coordinate in 256 went out a byte short — 29 of 4000 generated keys — and a strict JWK reader is entitled to reject the `signing_keys` the discovery profile advertises. Readers see the same key either way; a consumer comparing coordinate strings will see the short ones become padded ([#134](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/134))
- `PlatformProfile::fromArray()` reads back what `PlatformProfile::toArray()` writes. An empty `services`, `capabilities` or `payment_handlers` section is emitted as `stdClass` so it encodes as `{}` rather than `[]` — which is what the schema says it is — and `fromArray()` accepted only arrays, so a profile this class produced could not be parsed by this class. That round trip is what the discovery cache and the profile builder both rely on ([#161](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/161))

### Changed

- `ucp-php-sdk/core` no longer requires `phpseclib/phpseclib`, and now declares no runtime dependency beyond PHP and the extensions it calls. The library was used in one place — turning a public key into a PKCS8 PEM in `PublicSigningKey::fromJwk()` — while `ext-openssl` was already a hard requirement and already produced every key `DefaultSigningKeyManager` hands out. phpseclib 4.0 renamed its root namespace from `phpseclib3\` to `phpseclib4\`, which meant a consumer could no longer be given a constraint covering both majors without the SDK carrying a runtime namespace shim; a package that depends on neither cannot conflict with a consumer's dependency graph at all ([#133](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/133))
- `PublicSigningKey::$publicKeyPem` derived from JWK `x`/`y` coordinates is now formatted the way `ext-openssl` formats it: LF line endings with a trailing newline. phpseclib emitted CRLF without one, so the two producers of that property disagreed byte-for-byte over identical DER — `DefaultSigningKeyManager::toPublicKey()` passed openssl's PEM through while `fromJwk()` re-encoded it. Consumers comparing PEM strings rather than keys will see the difference; `openssl_verify()`, which is what the SDK does with it, accepts both ([#133](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/133))

## 0.0.5 - 2026-08-06

### Fixed

- Both packages now declare the PHP extensions their sources call: `ext-openssl` (signing and key handling in each), plus `ext-filter`, `ext-iconv` and `ext-mbstring` in `core`. Found by the new used-but-undeclared check on its first run ([#119](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/119))
- `ucp-php-sdk/symfony-bundle` now requires the four packages its `src/` uses directly and did not declare: `symfony/console` (35 use statements — the six signing-key commands extend `Symfony\Component\Console\Command\Command`), `psr/log`, `symfony/event-dispatcher-contracts` and `symfony/http-client-contracts`. They arrived transitively via `symfony/framework-bundle`, which does not hard-require console: installing the bundle on its own produced `Class "Symfony\Component\Console\Command\Command" not found` as soon as a command was autoloaded ([#117](https://github.com/agentic-commerce-alliance/ucp-php-sdk/issues/117))

### Changed

- `oneOf` and `anyOf` validation failures now say what rejected the payload. When no branch matched, each branch's own first violations are reported — closest branch first, named by the schema's `title` (`"Checkout"`, `"Shipping Destination"`) — and when several matched, the count and the names of the ones that did. The previous message, `$ must match exactly one allowed schema.`, was identical for both, and they need opposite fixes: add the field a branch wants, or remove the field that makes a second branch match ([#114](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/114))

### Changed (breaking)

- `OrderConfirmation::$permalinkUrl` is now required and non-nullable. `types/order_confirmation.json` marks both `id` and `permalink_url` required, so an order confirmation without a permalink can never produce a valid response — while the optional-and-filtered property made that the easiest object to build, and the failure surfaced as an opaque schema error on the *business's own* response. Consumers that relied on the default now get a type error where they construct it ([#114](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/114))

## 0.0.4 - 2026-08-05

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
