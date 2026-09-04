# Changelog

## Unreleased

### Added

- The UCP `2026-08-25` schema set is now pinned and generated alongside `2026-04-08`, and is inactive: the configured protocol version still defaults to `2026-04-08`, so nothing validates against the new tree yet. `tools/sync-ucp-schemas.php` learned the restructured layout — `error_response.json` moved from `shopping/types/` to `common/types/` and is now probed in both, so both versions stay reproducible from their pinned trees, and `source/discovery/` is gone in favour of `source/schemas/profile.json`. The cycle-guard placeholder budget is committed per version in `tools/sync-cycle-placeholder-budget.json`, because the new type graph is more recursive and a placeholder silently loosens validation where it lands ([#150](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/150))
- Line items and products can carry the sale basis their quantity is denominated in: `quantity_unit` and `unit_price`, backed by new `Unit`, `Measure` and `UnitPrice` models. `2026-08-25` keeps `quantity` an integer — "always an integer step count" — and adds the unit that the count counts, so half a kilo of coffee is 500 steps of a gram priced per kilogram rather than a quantity of 0.5. Both parameters are appended with defaults, so nothing existing changes shape ([#152](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/152))
### Changed (breaking)

- Negotiation failures answer with the status the spec assigns them instead of `400` for all of them. `capabilities_incompatible` is now `200`: the spec's error-code table puts it there, and its error-handling section explains why — a capability mismatch is a business outcome the handler reached on the inputs it was given, not a request the server could not process, so it belongs inside a UCP response with `ucp.status: "error"`. `version_unsupported` is now `422`. A platform reading only the status could not previously tell "your profile is unusable" from "we have nothing in common"
- The conformance lane applies the patches in `docs/upstream/` after checking out the pinned commit, and fails if one stops applying. The suite does not pass against any conformant merchant unpatched — its mock agent profile declares one capability while the tests exercise seven — so the lane previously depended on someone having applied them by hand. Nothing is enforced yet: the results are not reproducible from a clean clone, and `docs/conformance.md` records what has been ruled out ([#157](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/157))

### Fixed

- A UCP failure that the spec reports inside a successful response now actually carries that status. Symfony rewrites any non-error status to `500` when a listener handles a throwable that is not an `HttpException`, unless the listener marks the code deliberate, so the first attempt at the change above produced a correct `capabilities_incompatible` body under a `500` that claimed the server had broken

- Public signing key JWKs now carry `x` and `y` at the full width of the curve, as RFC 7518 section 6.2.1.2 requires. openssl returns EC coordinates as minimal-form integers and `DefaultSigningKeyManager::toPublicKey()` published whatever it was handed, so roughly one coordinate in 256 went out a byte short — 29 of 4000 generated keys — and a strict JWK reader is entitled to reject the `signing_keys` the discovery profile advertises. Readers see the same key either way; a consumer comparing coordinate strings will see the short ones become padded ([#134](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/134))
- Flattening a schema that carries both `properties` and `allOf` no longer discards its own properties. `tools/sync-ucp-schemas.php` seeded its `allOf` merge with an empty map, and nothing at `2026-04-08` had both, so this read as correct until `shopping/types/fulfillment_method.json` arrived with both at `2026-08-25`. Its `allOf` holds only `if`/`then` branches, which contribute no properties of their own, so the merge collected nothing and `fulfillment.methods[]` generated as `{"type": "object", "properties": []}` — an object accepting anything, on four request schemas, with no signal. The cycle-placeholder budget did not catch it because this is not a cut recursive `$ref`. Conditional branches now contribute the properties they introduce, as optional, while an unconditional definition is kept over a conditional narrowing of the same property: the validator cannot evaluate `if`, and under `allOf` semantics a valid payload satisfies the unconditional half regardless of which condition holds, so keeping it accepts every valid payload. Preferring the branch would reject valid ones — `unit.scale` is `0-15` and only `const 0` when `unit` is `C62` ([#150](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/150))
- A key type the SDK cannot verify with no longer rejects the entire platform profile. `PlatformProfile::signingKeys()` called `PublicSigningKey::fromJwk()` in a loop with no `try`/`catch`, and that method hard-throws for anything that is not EC on a recognised curve — so a single Ed25519 key in a peer's key set failed discovery outright, with an error naming a curve rather than the fact that the rest of the profile was usable. Unusable entries are now skipped, which `2026-08-25` requires of a JWK Set reader. `alg` and `use` are also no longer required, both being `OPTIONAL` in RFC 7517, and a JWK carrying private members (`d`, `p`, `q`, `dp`, `dq`, `qi`, `oth`, `k`) is rejected rather than cached ([#151](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/151))
- Buyer consent is read from `buyer.consent`, where every published schema locates it. `HttpPayloadMapper::toConsent()` read a top-level `buyer_consent` key that no schema defines, so consent sent by a conformant peer was discarded and `granted` defaulted to `false` — safe, but it made the feature inert. The old key is still accepted for one release because this SDK advertised it in its own MCP tool schemas ([#153](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/153))
- Capability negotiation now excludes a capability whose dependencies did not survive, however deep the chain runs. The `extends` filter ran once, against the name set as it was *before* anything was dropped: with A extending B extending C and no C on offer, B was dropped and A survived holding a dependency that was no longer there. Each removal can invalidate another, so the filter now runs to a fixed point ([#154](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/154))
- The merchant example emits `fulfillment` on checkout responses. UCP models fulfillment as a negotiation — the business publishes methods, the platform names a destination, the business prices the options that destination allows, the platform picks one — and each step needs the previous one's answer, so omitting the object left a platform with nothing to select. New `FulfillmentPlanner` builds it: destinations are echoed tagged with their contract type (`shipping_address` or `business_location`, required in business responses at `2026-08-25`), options appear only once a destination is settled, and nothing is charged until an option is selected. It also declines the reserved `fail_token` and refuses to complete a cancelled checkout, both of which previously succeeded. In the conformance lane this moved 5 passed / 59 failed to **18 passed / 45 failed** ([#157](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/157))
- `scripts/run-conformance.sh` copies `tests/conformance/payment_instruments.csv` over the suite's default. The suite reads payment instruments from a CSV rather than from the JSON fixtures the runner was already overriding, so every completion attempt used upstream's `mock_payment_handler` — a handler the merchant example does not implement — and reported as 24 occurrences of an unrelated-looking error ([#157](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/157))
- The `UCP-Agent` header's `version` parameter is now honoured. A platform naming a protocol version this release does not serve was answered in the shapes of the version it does, which is how two peers end up disagreeing about a field neither will mention; it is now refused with `version_unsupported` while the reason is still the version rather than whatever fails first because of it ([#157](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/157))
- The merchant example reads `UCP_MERCHANT_BASE_URI` through `getenv()` as well as the superglobals, and treats the loopback aliases as one origin when deriving `allowed_profile_hosts`. Whether the environment reaches `$_ENV` depends on `variables_order`, and under `php -S` it commonly does not — so the app silently fell back to a different host than the operator asked for, and then refused agent profiles served on the other spelling of the same address. Which agents it would talk to was decided by `variables_order` rather than by configuration ([#157](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/157))

### Changed

- `ucp-php-sdk/core` no longer requires `phpseclib/phpseclib`, and now declares no runtime dependency beyond PHP and the extensions it calls. The library was used in one place — turning a public key into a PKCS8 PEM in `PublicSigningKey::fromJwk()` — while `ext-openssl` was already a hard requirement and already produced every key `DefaultSigningKeyManager` hands out. phpseclib 4.0 renamed its root namespace from `phpseclib3\` to `phpseclib4\`, which meant a consumer could no longer be given a constraint covering both majors without the SDK carrying a runtime namespace shim; a package that depends on neither cannot conflict with a consumer's dependency graph at all ([#133](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/133))
- `PublicSigningKey::$publicKeyPem` derived from JWK `x`/`y` coordinates is now formatted the way `ext-openssl` formats it: LF line endings with a trailing newline. phpseclib emitted CRLF without one, so the two producers of that property disagreed byte-for-byte over identical DER — `DefaultSigningKeyManager::toPublicKey()` passed openssl's PEM through while `fromJwk()` re-encoded it. Consumers comparing PEM strings rather than keys will see the difference; `openssl_verify()`, which is what the SDK does with it, accepts both ([#133](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/133))
- The platform profile publishes its key set as `keys`, the RFC 7517 member name that `2026-08-25` requires, and reads `keys` before falling back to `signing_keys` for one release. The PHP property stays `$signingKeys` — that is the PHP-side name, not the wire name, and renaming it would break consumers for no protocol benefit ([#151](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/151))
- Capability negotiation reads the `version` each side publishes and the `requires` ranges the protocol has defined since `2026-04-08`, neither of which this SDK previously consulted. A capability offered at a version we do not implement is now excluded, together with its operations, rather than selected by name and failed further in with an error about a field instead of about the version. `requires.protocol` and `requires.capabilities` are enforced from both sides and their ranges intersected, so a requirement the two peers cannot both satisfy is a definite exclusion. New `VersionRange` and `CapabilityRequirements` models, and a trailing `?CapabilityRequirements $requires = null` on `CapabilityDescriptor`. **Adopters publishing a capability `version` that differs from their peers' will see that capability stop negotiating**; `requires` is how a range of tolerance is expressed ([#154](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/154))
- `checkout.update.request` and `cart.update.request` no longer accept a duplicated `id` in the body — the identifier travels in the path — and `checkout.update.request` requires `line_items`. The example capability descriptors now take their version from `UcpProtocolVersion::current()`, since a literal beside a `CapabilityDescriptor` is exactly the drift version-aware negotiation excludes on ([#155](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/155))
- The conformance lane runs at `ucp_version: 2026-08-25`, and the baseline moved from 1 passed / 63 failed to **5 passed / 59 failed** — with the wall in a more useful place: `capabilities_incompatible` on 59 of 63 became `KeyError: 'fulfillment'` on 44 of 59, which is the merchant example not populating fulfillment rather than negotiation failing. The lane stays advisory with an empty `enforced-modules.txt`, because the upstream suite pins `ucp-sdk==0.4.4` — the `2026-04-08` model set — and its newest commit predates the `2026-08-25` specification, so it cannot yet assert the new shapes; `docs/upstream/conformance-suite-protocol-version.md` records the blocker and the re-arm condition ([#156](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/156))

### Changed (breaking)

- `BuyerConsent` is now a map of consent purposes keyed by reverse-DNS identifier — `BuyerConsent(array $purposes)` with `granted(string $purpose, ?string $segment = null): bool` — replacing `BuyerConsent(bool $granted, ?string $timestamp)`. Neither removed member has ever existed on the wire at any published version: `2026-04-08` defines `buyer.consent` as four booleans (`marketing`, `analytics`, `preferences`, `sale_of_data`) and `2026-08-25` replaces those with the purpose map. Each purpose carries `granted`, `source` (`business` or `platform`), a description, and optional per-segment refinements whose `granted` overrides the parent's — which is what makes "marketing, but not SMS" expressible. `BuyerConsent::fromArray()` still accepts the legacy booleans and maps them onto the well-known purposes, `sale_of_data` becoming `dev.ucp.consent.sale_or_sharing`; a migrated boolean is attributed to `platform`, because a bare flag makes no claim to have been recorded by the business. Note that this tolerance is reachable only when calling the model directly: at `2026-08-25` the request schema constrains `buyer.consent` with `propertyNames: reverse_domain_name`, so schema validation rejects a legacy consent map before the mapper sees it ([#153](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/153))
- **The active protocol version is now UCP `2026-08-25`.** `ucp_sdk.version` defaults to it, the runtime validates against `generated/2026-08-25`, and response envelopes carry it. `generated/2026-04-08` is deleted; `pinned/2026-04-08` is kept one release as a diffing reference. `UcpProtocolVersion::V20260408` is retained so persisted rows and historical profiles still parse, but it is no longer servable — configuring it now fails at container build with "Unsupported UCP protocol version" rather than with a missing-directory error. `supportedVersions()` means servable and returns exactly one version; the new `knownVersions()` is every version the enum can name ([#155](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/155))
- Merchants must update the shapes they emit, because 17 of the 30 generated schemas close `additionalProperties` and these are response-side: a fulfillment destination now requires a `type` discriminator alongside `id` (`shipping_address` or `business_location`, replacing an untagged `oneOf` of address-or-location); `fulfillment_option.description` is a `description` object with at least one of `plain`, `html` or `markdown` rather than a string; `business_fulfillment_config` renames `allows_multi_destination` to `multi_destination` (now a list) and `allows_method_combinations` to `method_combinations`; and `card_credential` is deprecated in favour of `pan_credential` (`type: "pan"`) and `network_token_credential` (`type: "network_token"`), the choice following the shape of the value on the wire — a token in PAN form verified with a `cvc` is a PAN credential, one verified with a discrete `cryptogram` is a network token. The SDK needs no code change for any of these: they travel through `FulfillmentSelection::$extra` and `PaymentInstrument`'s free-string `$type` and open `$credential`, which is precisely why they fail as *response* validation errors on the business's own payload rather than at a type boundary ([#155](https://github.com/agentic-commerce-alliance/ucp-php-sdk/pull/155))

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
