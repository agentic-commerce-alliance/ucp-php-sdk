# Changelog

## 0.0.6 - 2026-09-11

### Changed (breaking)

- Serve UCP `2026-08-25`. `2026-04-08` remains a known historical version but can no longer be configured as the active version. Adopters must update fulfillment destinations, description objects, payment credentials and cart/checkout update payloads to the new schemas.
- Publish profile signing keys as `keys`; accept legacy `signing_keys` for this release. Negotiate capability versions and `requires` ranges, and remove capabilities whose dependencies are unavailable.
- Replace `BuyerConsent(bool, ?string)` with a reverse-DNS purpose map and purpose-aware `granted()` lookup. Read consent from `buyer.consent`; legacy model parsing does not bypass the new request schema.
- Remove the invented `UcpCapability::CatalogProduct` identifier; product detail uses `CatalogLookup`. Add the specification's discount, fulfillment, consent and identity-linking identifiers.
- Emit fixed-width ECDSA signatures and RFC 9421 algorithm names. Verification temporarily accepts legacy DER signatures and JWA names; stored keys do not need migration.
- Return `200` with a UCP error envelope for incompatible capabilities and `422` for unsupported protocol versions.

### Added

- Real product descriptions with title fallback (#98), and quantity-unit and unit-price models for products, variants and line items.
- Optional request-bound REST response signing, Ed25519 keys through the newly required `ext-sodium`, RFC 7638 thumbprint key IDs, and `Signature-Agent` / `web-bot-auth` verification.
- Profile-cache freshness and ETag revalidation through an optional extended repository interface. The DBAL cache gains a nullable `etag` column; `platform_profile_cache_ttl` has a 60-second minimum.
- Public shopping-operation and schema-bootstrap APIs, warnings for profiles without usable signing keys, reproducible schema generation, upstream drift reporting and broader test/coverage gates.
- A reproducible merchant conformance lane for `2026-08-25`, twelve enforced modules and a separate strict-signature pass.

### Fixed

- Verify the peer's actual covered signature components, require digests when appropriate, accept signatures without `expires`, and accept the default `sig1` tag.
- Serve product detail at `POST /ucp/v1/catalog/product`; retain the legacy GET route behind `legacy_routes.catalog_product_get` for migration.
- Publish all payment handlers, pad EC coordinates correctly, accept usable keys from mixed JWK sets, and unify request-specific agent-domain allowlists (#97).
- Preserve idempotent response bytes, read discount-code strings, add webhook delivery identity/retries, and improve the merchant example's fulfillment and environment handling.
- Remove the core package's phpseclib dependency. Core requires only PHP and extensions; JWK-derived PEM strings now use LF with a trailing newline.

### Upgrade notes

- Install both SDK packages at `0.0.6`; the Symfony bundle requires core `>=0.0.6 <0.1.0`. Enable `ext-sodium` and rerun `SchemaBootstrapper::ensureSchema()` when using default DBAL storage.
- This remains a pre-1.0 release. Full AP2 credentials and platform-specific MCP runtimes are outside the shared SDK. Full conformance is not claimed: see the GitHub Release for measured results and remaining limitations.

## 0.0.5 - 2026-08-06

### Fixed

- Both packages now declare the PHP extensions their sources call: `ext-openssl` (signing and key handling in each), plus `ext-filter`, `ext-iconv` and `ext-mbstring` in `core`. Found by the new used-but-undeclared check on its first run ([#119](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/119))
- `ucp-php-sdk/symfony-bundle` now requires the four packages its `src/` uses directly and did not declare: `symfony/console` (35 use statements — the six signing-key commands extend `Symfony\Component\Console\Command\Command`), `psr/log`, `symfony/event-dispatcher-contracts` and `symfony/http-client-contracts`. They arrived transitively via `symfony/framework-bundle`, which does not hard-require console: installing the bundle on its own produced `Class "Symfony\Component\Console\Command\Command" not found` as soon as a command was autoloaded ([#117](https://github.com/agentic-commerce-alliance/ucp-php-sdk/issues/117))

- The conformance lane was running the merchant example in `dev`, not `prod`, and every number reported from it was measured that way. `index.php` read `APP_ENV` from `$_SERVER` and `$_ENV` only, and under PHP's built-in server an exported variable reaches neither -- `variables_order` governs the first and CGI-style population the second -- so `APP_ENV=prod` silently resolved to `dev`, which is what the runner's own comment says must not happen because dev enables development-mode profile fetching. `Kernel` had already been fixed to consult `getenv()`; the one read that decides whether those affordances exist at all had not. In genuine prod the lane scores 1 passed / 63 failed, because the suite serves its mock agent profile over plain http on loopback and the SDK refuses that outside development mode -- correctly, since a profile carries the keys that verify every request from that platform. So the affordance is genuinely required for a run against a localhost mock, and is now enabled explicitly through `UCP_PROFILE_FETCHING_DEV_MODE` in the one command that needs it rather than inherited from the whole dev container. With that single relaxation declared and everything else at prod, the lane scores the same 58 passed / 6 failed, which is also the evidence that dev mode was buying nothing else ([#171](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/171))
- `allowed_agent_domains` had two incompatible readings, and both fail closed, so whichever form a merchant configured one feature silently refused everything. The platform-profile gate matched entries as bare domains with subdomain matching, so `https://agent.example` was compared against a host of `agent.example` and matched nothing that could exist; the embedded transport's CORS check normalised entries as full origins and dropped anything without a scheme, so `agent.example` produced an empty allow-list. `agent.example` therefore gated profiles correctly and refused every agent's frame, and `https://agent.example` did the reverse -- and because refusal is the safe direction in both, the symptom was "the embedded transport does not work" rather than anything that looked like a bug. Both now read one shared allow-list. An entry is a domain, which covers its subdomains and admits https only -- plus http for `localhost`, `127.0.0.1` and `::1`, a carve-out the profile-fetching development mode already had -- or a full origin, which is then compared as one, exactly, since spelling out a scheme is a request to be specific. A bare domain does not cover a non-default port, because a port is part of an origin and `localhost:8081` is very likely somebody else's application. An entry the SDK cannot act on now fails container compilation instead of being skipped at request time. An entry may also be an address literal in any of its spellings -- `127.0.0.1`, `::1`, `[::1]` -- which is how the loopback set is configured, since those and `localhost` are one host and which appears depends on how the app was started rather than on who it should trust; an address is matched exactly, because it has no subdomains and suffix-matching one would admit a name that merely ends in those digits ([#169](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/169))
- `ConnectionFactory` no longer carries a fallback for a Doctrine DBAL without `DsnParser`. That class has shipped since DBAL 3.6 and `ucp-php-sdk/symfony-bundle` requires `^3.7 || ^4.0`, so the `class_exists()` guard could never take its other branch. A fallback that cannot run is worse than none: it reads as support for a configuration nothing tests ([#169](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/169))

- A business publishing a profile it cannot sign anything with now says so in the log. With no key provisioned and `signing_keys.auto_generate` off, the profile provider returns an empty key set and nothing downstream treats that as unusual: discovery succeeds, `keys` is published empty, and no platform can verify anything the business sends -- the first visible symptom being a webhook dispatch that reads as the business never announcing the order. The warning fires once per process on the first profile build, and only when `signature_policy` is not `off`, because a business that signs nothing is not misconfigured. It is not a container-build check on purpose: at build time a key provisioned by hand is indistinguishable from no key at all, so the warning would be wrong about as often as it was right, and one that cries wolf teaches operators to filter it ([#170](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/170))
- The merchant example generates its signing key on first use, so it boots into a state where it can sign what it publishes rather than needing a provisioning step nothing tells you about. A real merchant should leave `auto_generate` off and run `ucp:signing-keys:generate`, which is why the SDK warns instead of doing this for everyone: a key that appears by itself is a key nobody decided to trust ([#170](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/170))
- A signature carrying no `expires` is no longer refused. The specification's default signature shape is `created` and `keyid` and nothing else -- `expires` belongs to the web-bot-auth shape -- and this SDK required it, so it rejected every peer signing the way the spec describes, with `Signature-Input is missing required parameters.`, a message that named none of them. It is the same class of defect as emitting DER signatures: self-consistent, green against our own signer, and unable to talk to anyone else. An explicit `expires` is still honoured; without one the signature's age is bounded by the same window, so dropping it does not turn a captured signature into a permanent credential. Found by `shopware/ucp-conformance-agent`, an external agent that derives its signer from the specification rather than from any implementation -- one bug that accounted for five of nine non-passing verdicts, because it blocked every signed request ([#172](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/172))
- The published profile is cacheable. Nothing set `Cache-Control` on `/.well-known/ucp`, so Symfony's default `no-cache, private` went out -- the SDK was telling every platform not to cache the one document they all fetch, and to treat it as private when it is the same for every caller by construction. It is now `public` with a `max-age` from the new `ucp_sdk.profile_cache_max_age` (default 300, floor 60, which is the spec's) plus `must-revalidate` ([#173](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/173))
- `code_challenge_methods_supported: ["S256"]` is advertised in the OAuth authorization-server metadata. RFC 8414 reads an omitted value as "not supported", so a correct agent had to conclude PKCE was unavailable and fall back to a bare authorization code -- the flow PKCE exists to replace. `plain` is deliberately absent, being a downgrade an authorization server should not offer ([#173](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/173))
- An unsupported A2A method answers `200` with a JSON-RPC `-32601` error instead of HTTP `404`. A JSON-RPC client reads the envelope; a 404 says the *endpoint* is missing, which is a different problem with a different remedy, and it discards the error object naming the real one. The parse and invalid-params paths still answer 4xx, because a body that cannot be decoded has no request id to answer with and so no envelope to carry an error ([#173](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/173))
- The merchant example refuses an unknown cart id instead of inventing an empty cart for it. It returned a cart carrying a `cart_not_found` warning, so an agent reading the status rather than the messages would add items to something the business does not have -- and the fabricated cart had no totals, so it failed response validation and the caller received `invalid_request` about our own response rather than `not_found` about their id ([#173](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/173))

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
