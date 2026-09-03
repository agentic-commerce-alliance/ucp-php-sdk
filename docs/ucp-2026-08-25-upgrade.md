# UCP 2026-08-25 Upgrade Backlog

Status: in progress. Owner: TBD.

| Wave | Tasks | State |
| --- | --- | --- |
| 0 — prep | T1, T2, T3, T31 | done |
| 1 — interop breakers | T4, T5, T6, T7, T8 | done · **T9 withdrawn**, premise false |
| 2 — conformance harness | T10, T11, T12 | done · T13, T14 open |
| 3 — the hard switch | T15 to T22 | T15 done · T17 rescoped by it, no longer breaking |
| 4 — signature parity | T23 to T26 | not started |
| 5 — sustainability | T27 to T30, T32 | not started |
| discovered | **T33** | done — found by T12; premise corrected, see its entry |
| docs | **T32** | done |

This document is the authoritative gap statement between this SDK and the UCP
specification. It supersedes the transport-only notes that used to live in
[full-ucp-parity-plan.md](full-ucp-parity-plan.md).

Each `T*` section is written to be pasted into a GitHub issue as-is. The
companion plugin-side backlog lives in the `SwagAgenticCommerce` repository at
`docs/ucp-sdk-integration-backlog.md`; cross-repo ordering is recorded in both.

## Context

The SDK pins protocol version `2026-04-08`. Upstream published `v2026-08-25` on
2026-08-25: a large, explicitly breaking release covering a multi-vertical
refactor, a restructured schema tree, canonical `keys[]`, reverse-DNS buyer
consent, fractional quantities, 3DS2 via actions, `$requestConstraints`, and
capability versioning. Upstream `main` already carries next-release work, so the
target moves roughly every four months.

Two problems compound the version gap.

**1. The version cannot be bumped today.** Version configuration and schema
validation are wired independently:

- `packages/symfony-bundle/src/DependencyInjection/UcpSdkExtension.php:304`
  hardcodes `resources/schema/generated/2026-04-08`.
- `packages/symfony-bundle/src/Operation/ShoppingOperationExecutor.php:391`
  hardcodes `UcpProtocolVersion::V20260408->value` into every response envelope.

Setting `ucp_sdk.version: '2026-08-25'` would advertise the new version while
validating against the old schemas and stamping the old version into responses,
silently, with a green test suite.

**2. Several deviations break interop at `2026-04-08` already.** Most
consequentially, ECDSA signatures are emitted as DER from `openssl_sign` where
RFC 9421 requires fixed-width raw `r||s`; and `POST /catalog/product`, which is
in the upstream OpenAPI document at both versions, is served as
`GET /ucp/v1/catalog/product/{id}`.

The root cause of both surviving unnoticed is structural: the SDK validates
itself against schemas it flattened itself, using a hand-rolled JSON-Schema
subset whose non-local `$ref` resolution returns "pass". Upstream now publishes a
language-agnostic conformance suite
([Universal-Commerce-Protocol/conformance](https://github.com/Universal-Commerce-Protocol/conformance))
that runs against any live UCP merchant server, and it would have caught both.

**Intended outcome:** the SDK serves UCP `2026-08-25`, passes an upstream
conformance lane in CI, and has a drift-detection job so the next release is
noticed rather than discovered.

## Which task fixes which defect

| # | Defect | Fixed by | Mechanism |
|---|---|---|---|
| 1a | `UcpSdkExtension.php:304` hardcodes `generated/2026-04-08` | **T1** | Build the path from `$config['version']` behind a `SchemaDirectoryLocator` so core, bundle and tests resolve it through one function. Missing directory becomes a container *build* failure. |
| 1b | `ShoppingOperationExecutor.php:391` hardcodes the envelope version | **T1** | Read the version from the injected `RuntimeConfiguration`. |
| 1c | Nothing detects that 1a and 1b disagree with config | **T1** | A drift-guard test asserts config default == wired schema directory == emitted envelope version. |
| 2a | ECDSA emitted as DER; `alg` uses JWS names | **T4** | `EcdsaSignatureCodec` plus a `SignatureAlgorithm` enum carrying the storage/wire/curve/hash mapping. |
| 2b | Peer's covered-component list parsed then discarded | **T5** | Carry the ordered list through parsing; rebuild the base from it; reconstruct `@signature-params` verbatim. |
| 2c | `Content-Digest` required on bodyless GETs | **T6** | Verify only when `content-digest` is covered, and reject a body whose covered list omits it. |
| 2d | `GET .../product/{id}` instead of `POST /catalog/product` | **T7** | Add POST; keep GET behind a config flag with a deprecation path. |
| 3 | The SDK grades its own homework | **T12 → T13 → T14**, plus **T2** and **T27** | External conformance oracle, reproducible schema generation, drift detection. |

Waves 0 to 2 (T1 to T14, plus T31) exist entirely to close this table. `T9` was
withdrawn during Wave 1 -- see its entry -- so the wave is T4 to T8 plus T10 to T14. The
`2026-08-25` bump itself is Wave 3.

## Decisions

| Decision | Choice |
|---|---|
| Version strategy | **Hard switch** to `2026-08-25`. No runtime multi-version support. Pre-1.0, so breaks are permitted with CHANGELOG entries. |
| New-capability scope | **Core breaking absorptions only**: `keys[]`, reverse-DNS consent, fractional quantity, fulfillment restructure, `cart.id` omission, payment namespace migration, capability versioning. |
| Conformance suite | **Adopt** it: a CI lane against `examples/merchant-symfony-app`, advisory first, then per-module blocking. |

### Why a hard switch rather than side-by-side versions

Multi-version *validation* is cheap: `DefaultProtocolValidator` already receives
a `RequestContext` carrying both the negotiated profile version and the runtime
configuration, so a validator registry keyed by version costs one internal class
change and zero public-API change.

Multi-version *serialization* is the expensive part, and it is what a peer
actually needs. `HttpPayloadMapper` holds one set of inbound wire literals and
every core model owns exactly one `toArray()`. Two wire versions means either
version branches inside roughly twenty `toArray()` methods or a parallel
serializer tree.

The `2026-08-25` breaks are also not a superset: consent booleans become a
reverse-DNS map, fulfillment flags drop the `allows_` prefix, payment extensions
move namespace, PAN and network token split, profile `signing_keys` becomes
`keys`. These are renames. A single model set cannot emit both.

Finally, `supported_versions` today is advertisement only.
`ShoppingOperationExecutor.php:90` gates membership and then answers in whatever
single shape the SDK emits. Listing `2026-04-08` there after the bump would be a
claim enforced by nothing, which is the same class of defect as the hardcoded
schema directory.

If dual-version service is genuinely needed later, the seam is:
`DefaultProtocolValidator` takes `array<string, SchemaValidatorInterface>` keyed
by version and selects on
`$context->platformProfile?->version ?? $context->runtimeConfiguration?->version`;
DI builds one `GeneratedSchemaValidator` per entry in `version` union
`supported_versions`. Do not build it until there is a versioned serializer to
pair with it: a validator that accepts `2026-04-08` while the mapper only speaks
`2026-08-25` is strictly worse than rejecting the request.

## Explicitly out of scope

Not built in this round: `actions[]`, `policies[]`, `$requestConstraints`
evaluation, location search/lookup, permalink, loyalty, payment terms and
schedules, split payments, 3DS2 machinery, MCP runtime transport, an AP2 verifier
in core, DPoP, an inbound webhook receiver, refund and return *operations*, tax,
product feed, side-by-side dual-version service, and anything only on upstream
`main` (lodging vertical, fee extension, flat loyalty, totals-object refactor,
first-class errors, constraints-jsonschema).

Four of these need a recorded rationale rather than silence.

**`$requestConstraints` evaluation.** `GeneratedSchemaValidator` is a hand-rolled
JSON-Schema 2020-12 *subset* whose non-local `$ref` resolution returns `[]`, i.e.
passes; `tools/sync-ucp-schemas.php` exists specifically to flatten refs around
that limitation. Layering a constraints dialect on that foundation means either
writing a real JSON-Schema implementation inside a zero-dependency package, or
shipping validation that silently passes. If constraint validation becomes
mandatory, the correct move is to add `opis/json-schema` to the **Symfony
bundle**, which already has dependencies, and leave `packages/core` untouched.
That deserves its own design discussion, not a rider on a capability PR.

**MCP runtime transport.** `full-ucp-parity-plan.md` already commits to the
opposite architecture in writing, and the Shopware plugin already ships the
runtime: a `/ucp/mcp` endpoint proxying to `/store-api/_mcp` with the
sales-channel access key kept server-side, plus its own MCP tools. Building an
MCP runtime here would contradict that decision, require owning MCP session
lifecycle and streamable-HTTP transport inside a framework-free zero-dependency
package, and expose a second surface over the same operations REST already
serves. The reusable part is a tool-descriptor generator off the operation
registry, not a transport.

**An AP2 verifier in core.** `UnsupportedMerchantAuthorizationService` plus
`PaymentMandateVerifierInterface`, `PaymentMandateVerificationEvent` and the
`ap2.enabled` flag is already the right design: the extension point exists and no
verifier ships. Mandate verification is trust-anchor and jurisdiction specific; a
default implementation in a merchant SDK would be a liability dressed as a
convenience. A *reference* verifier belongs in the example app.

**An inbound webhook receiver.** For a merchant-side SDK the agent-to-merchant
direction *is* the REST surface, which already has signature verification, a
replay guard and idempotency. Until upstream defines a normative
merchant-inbound webhook with a payload schema, a receiver would be a generic
signed-POST endpoint with no defined semantics.

## Gap summary

| Area | State today | `2026-08-25` requires |
|---|---|---|
| Schema tree | `pinned/2026-04-08` plus 30 flattened `generated/` files | Restructured: `common/types/*`, `schemas/profile.json` replaces `discovery/`, `common/payment_*`, new services under `services/common` |
| Profile signing keys | top-level `signing_keys[]`, EC only, one bad key rejects the whole profile | `keys[]` JWK Set only; open `kty`/`crv`/`alg` vocabulary; an unsupported key must not reject the profile |
| Buyer consent | `BuyerConsent(bool $granted, ?string $timestamp)` | reverse-DNS keyed map of `consent_purpose` with per-segment opt-ins |
| Quantity | `int $quantity`; `(int) ($row['quantity'] ?? 1)` casts an object to `1` | `anyOf` integer or `measure.json`, scale <= 15, integer bound +/-(2^53-1) |
| Capability negotiation | name-only `array_intersect_key`; `version` published but never read; `supported_versions` never consulted | per-entity versions plus `requires: {protocol:{min,max}, capabilities:{...}}` intersection |
| Payment namespaces | `dev.ucp.shopping.*` | `dev.ucp.common.payment.*`; PAN and network token split into distinct credential types |
| Fulfillment | `allows_`-prefixed flags, flat `description`, `multi_destination` map | prefix dropped, structured `description`, array of destinations, `type` enum opened, explicit `shipping`/`pickup` |
| ECDSA signatures | DER from `openssl_sign` (~71 bytes, leading `0x30`); `alg="ES256"` | fixed-width raw `r||s` (64/96 bytes); registry ids such as `ecdsa-p256-sha256` |
| Signature base | peer's covered-component list parsed then discarded; always rebuilt from a fixed 3-component list | base built from the received covered list, order significant |
| `Content-Digest` | mandatory on every request including bodyless GETs | representation metadata; absent for bodyless requests |
| Response signing | none (requests verified, webhooks signed) | normative "REST Response Signing" section |
| WBA interop | none; `kid` is an operator string defaulting to `"default"` | `Signature-Agent`, `tag="web-bot-auth"`, `keyid` = RFC 7638 thumbprint, Ed25519 recommended |
| Catalog product route | `GET /ucp/v1/catalog/product/{id}` | `POST /catalog/product` (unchanged since `2026-04-08`) |
| Conformance | self-validated only | upstream suite exists and is language-agnostic |

## Constraints the ordering is built around

Five verified facts drive the sequencing. Ignoring any of them turns the work
into one unreviewable PR.

1. **`scripts/bc-check.sh` has no ignore or baseline mechanism.** The run against
   `origin/<base_ref>` is blocking with no escape hatch, so the deliberate breaks
   in T17 and T18 are red by construction until an allowlist exists. This is the
   hardest prerequisite and it belongs in Wave 0.
2. **17 of the 30 generated schemas set `"additionalProperties": false`**,
   including every cart, checkout, order and catalog-product response. Any slice
   that *adds* a response field must land at or after the flip; slices that
   *dual-accept* on input can land before it, because
   `checkout.create/update.request` are not closed.
3. **The platform profile is never schema-validated at runtime.**
   `GeneratedSchemaValidator` only resolves flat
   `<operation>.<request|response>.json`, and
   `pinned/2026-04-08/discovery/profile_schema.json` is never loaded. So T16, T20
   and T8 are order-independent relative to the flip.
4. **`tools/sync-ucp-schemas.php:161` `responseWithError()` hardcodes
   `shopping/types/error_response.json`**, used by 10 of 30 operation entries plus
   the `catalog.product.response` `oneOf`. That file is
   `common/types/error_response.json` at `2026-08-25`, so the tool dies on the
   first cart operation. Its cycle guard also substitutes an accept-anything
   placeholder, and the new type graph is far more recursive, so placeholder count
   will rise and validation will silently loosen with no signal.
5. **The 80% MSI mutation gate on `packages/core/src/Internal/Security`**
   (`infection.security.json.dist`) means every signature slice needs
   mutation-grade tests. Budget roughly +40% effort on Wave 1 and Wave 4. This is
   the most under-estimated cost in this backlog.

Verified as *not* problems: `source/handlers/tokenization/openapi.json` still
exists at `2026-08-25`; the `$defs` pointers the sync tool depends on
(`search_request`, `lookup_request`, `get_product_request`,
`cart.json#/$defs/checkout`, and `dev.ucp.shopping.checkout` in `discount.json`,
`fulfillment.json` and `buyer_consent.json`) all survive; `examples/*/var/` is
gitignored and no compiled container is tracked.

---

# Backlog

Effort is S/M/L. **[BC]** marks a deliberate public break requiring a CHANGELOG
entry and an allowlist line.

## Wave 0 — Prep: make the bump possible and reviewable

No protocol change. The config default stays `2026-04-08`. The public-API
snapshot and Roave stay clean.

### T1 — `fix(bundle): derive the protocol version from configuration in one place`

**Why.** The two latent bugs that make the version un-bumpable, fixed
behaviour-neutrally so the flip later becomes a one-line change. Bumping config
alone leaves validation on the old schemas and, because `2026-04-08` is more
permissive on `checkout.create/update.request`, the test suite stays green.
Bumping the schema directory alone ships a wrong version string to every agent.

**Files.**
- `packages/symfony-bundle/src/DependencyInjection/UcpSdkExtension.php:304` — build the schema directory from `$config['version']`; fail at container build time when the directory is absent.
- `packages/symfony-bundle/src/DependencyInjection/Configuration.php:20` — validate the node against `UcpProtocolVersion::tryFrom()`.
- `packages/symfony-bundle/src/Operation/ShoppingOperationExecutor.php:391` — read the version from `RuntimeConfiguration` instead of the enum constant.
- New `SchemaDirectoryLocator` (or a static on the enum) so core, bundle and tests resolve the directory through one function. Moving the literal only relocates the bug.

**Acceptance.** A drift-guard test asserts the `Configuration` default, the wired
schema directory and the emitted envelope version are the same string. Setting an
unknown `version` fails container compilation with a message naming the missing
directory. `composer qa` green; snapshot unchanged.

**Effort.** S · **Depends on.** none · **Blocks.** everything

### T2 — `chore(ci): add a declared-BC-break allowlist and harden the sync tool`

**Why.** Roave has no per-symbol ignore, and the check against
`origin/<base_ref>` is blocking. Without an allowlist, T17 and T18 are red by
construction and the plan degenerates into one unreviewable PR. Separately, no CI
job runs the sync tool or diffs the pinned tree against upstream, so drift is
invisible.

**Files.**
- `scripts/bc-check.sh` — subtract lines matched by a committed `.bc-allowed-breaks.txt` (one Roave output line plus a CHANGELOG reference per accepted break) before deciding failure. **Keep the existing "no verdict line means failure" guard intact** — a silent install failure must not read as compatible.
- `tools/sync-ucp-schemas.php` — add `bool $required = true` to `mirrorDirectory()` and `fail()` on a missing source (the guard that would have caught the `source/discovery` deletion); drop the `'2026-04-08'` default at line 10 so an operator cannot regenerate the wrong version by omission; add a `--verify` mode that regenerates from `pinned/<version>` into a temp directory and diffs against `generated/<version>`, which needs the `$schemaRoot = $source . '/source/schemas'` computation at line 18 parameterised; count cycle-guard placeholders and fail above a committed baseline.
- `composer.json` — add `sync:verify` to the `qa` script. It is offline, so it fits the Docker CI model.

**Acceptance.** An accepted break listed in the allowlist passes `bc-check`; an
unlisted one still fails; a missing verdict line still fails. `sync:verify` is
green on `main` today — if it is not, that is itself a finding worth its own
issue. A hand-edited generated schema fails it.

**Effort.** M · **Depends on.** T1 · **Blocks.** T15, T17, T18

### T3 — `refactor(tests): centralise the generated-schema-directory literal`

**Why.** `GeneratedSchemaValidatorTest.php` hardcodes
`dirname(__DIR__, 2) . '/resources/schema/generated/2026-04-08'` seventeen times
and reads one schema file by path directly. Routing them through one helper makes
T21's rename a one-line diff.

**Scope note.** Leave the roughly 150 *protocol-version* literals in test data
alone. A test that asserts against `UcpProtocolVersion::current()` asserts
nothing; a literal is the correct thing to write there. Only the directory path
is centralised here.

**Effort.** S · **Depends on.** none

### T31 — `refactor(bundle)!: promote ShoppingOperation* and SchemaBootstrapper to public API`

**Why.** `SwagAgenticCommerce` depends on three `@internal` symbols as if they
were public, and the annotation is protecting nothing while hiding real breakage
from the BC gate:

| Consumer | Symbol | Blast radius |
|---|---|---|
| 13 MCP tool classes | `Symfony\Operation\ShoppingOperationExecutor` and `ShoppingOperationRequest` | the plugin's whole Store API MCP surface breaks at once |
| plugin `install()`/`update()` | `Symfony\Bridge\DoctrineDbal\SchemaBootstrapper` | **plugin installation** fatals, not just runtime |

`ShoppingOperationExecutor`/`ShoppingOperationRequest` are the natural public
"execute a UCP operation on any transport" seam, and `SchemaBootstrapper` is the
natural public installer API. Promoting them brings them under the public-API
snapshot and Roave, which is the point.

**Files.** Drop `@internal` from
`packages/symfony-bundle/src/Operation/ShoppingOperationExecutor.php:39`,
`ShoppingOperationRequest.php:9`, and
`packages/symfony-bundle/src/Bridge/DoctrineDbal/SchemaBootstrapper.php:14`. Add
their namespaces to `tools/build-public-api-snapshot.php` (note it currently
reflects `packages/core/src` only, so the bundle needs adding to its scan set).
Update `tools/internal-class-allowlist.php` and
`tools/check-internal-class-references.php`. Document in
`docs/extension-contract.md`.

**Acceptance.** The three classes appear in
`tools/public-api-snapshot.expected.txt`. `dead-code:internal-refs` passes.
`SymfonyInternalApiBoundaryTest` updated. A subsequent signature change to any of
them produces a Roave finding.

**Note for T7.** Removing the `catalog.product` `$id` side-channel touches
`ShoppingOperationRequest`'s fourth positional parameter
(`?string $id = null`), which all 13 plugin tools construct. Keep the parameter;
only stop *using* it for that one operation.

**Effort.** M · **Depends on.** none · **Pairs with.** plugin `P3`

## Wave 1 — Interop breakers (valid at `2026-04-08`, independent of the bump)

Each of these independently means "no conformant peer can talk to us". They are
not features; they are a broken handshake.

### T4 — `fix(security)!: emit RFC 9421 fixed-width ECDSA signatures and registry alg ids`

**Why.** `openssl_sign()` returns DER (`SEQUENCE { INTEGER r, INTEGER s }`,
roughly 70 to 72 bytes, leading `0x30`) and
`Rfc9421RequestSignatureService.php:40,47` ships it verbatim. RFC 9421 requires
fixed-width `r||s`: 64 bytes for P-256, 96 for P-384. Every signature we emit is
rejected by every conformant verifier, and every conformant signature we receive
is rejected by us. Additionally `alg="ES256"` is a JWS name; the RFC 9421
registry id is `ecdsa-p256-sha256`.

**The non-obvious part.** `ManagedSigningKey->algorithm` is *persisted* as
`ES256`/`ES384` by `DoctrineDbalSigningKeyRepository`. Do not rename the stored
value in this PR: that would couple a wire bug to a data migration. Map
internally between storage and wire ids and keep storage as-is; a later cleanup
can normalise the column.

**Files.** New `packages/core/src/Internal/Security/EcdsaSignatureCodec.php`
(`derToRaw(string, int $coordinateBytes)`, `rawToDer(string)`); new
`packages/core/src/Enum/SignatureAlgorithm.php` holding the storage/wire/curve/hash
mapping; `Rfc9421RequestSignatureService.php` `SUPPORTED_ALGORITHMS` (line 19),
`Signature-Input` emission (line 35), sign (line 40), verify (lines 47, 107) and
`opensslAlgorithm()` (lines 217-220);
`packages/symfony-bundle/src/Command/GenerateSigningKeyCommand.php` and
`ShowPublicSigningKeysCommand.php` for the displayed name.

**Acceptance.**
- `rawToDer(derToRaw($der)) === $der` across a corpus that explicitly includes **short-`r`/short-`s`** cases (a 30- or 31-byte integer must left-pad to 32) and **high-bit** cases (DER's leading `0x00` padding must be stripped, not carried into the raw form). These two are where every from-scratch implementation is wrong; make them named fixtures.
- Verification succeeds against a fixture signature produced by an **external** RFC 9421 implementation (Python `http-message-signatures`, or the upstream JS SDK). Do not generate it with our own signer — that reproduces the closed loop this whole effort exists to break.
- Emitted `Signature` decodes to exactly 64 bytes for P-256 and 96 for P-384.
- `Signature-Input` emits `alg="ecdsa-p256-sha256"`; verification accepts **both** `ES256` and the registry id for one transition release, with a test asserting both while only the registry id is emitted.
- An existing DB row with `algorithm = 'ES256'` still signs correctly with no migration, proven by a `DoctrineDbalSigningKeyRepositoryTest` case.
- `composer mutation:security` stays at or above 80% MSI.

**Effort.** M · **Depends on.** none · **Blocks.** T23, T24

### T5 — `fix(security): build the signature base from the peer's covered-component list`

**Why.** `parseSignatureInput()` keeps `keyid`, `alg`, `created` and `expires` and
throws the component list away (line 148); `signatureBase()` then always rebuilds
`("@method" "@target-uri" "content-digest")` (lines 35 and 100). Any peer that
covers `@authority`, `@path`, `content-type`, or the same components in a
different order, verifies against a base we invented and fails 100% of the time.
This is the deepest of the Wave 1 bugs and it gates T6, because "is
`content-digest` covered?" is the question T6 needs answered.

**Files.** `Rfc9421RequestSignatureService.php` — widen `parseSignatureInput()`'s
return to carry the ordered component list; `signatureBase()` takes that list;
reconstruct the `@signature-params` line **verbatim from the received
serialization** rather than re-serialising, because parameter ordering and
whitespace are exactly where naive implementations break. New
`packages/core/src/Internal/Security/SignatureComponentResolver.php` mapping
component ids to values from `Ucp\Sdk\Model\Http\HttpRequest`.

**Acceptance.**
- Verifies an externally generated signature whose covered list is `("@method" "@authority" "@path" "content-digest" "content-type")` in that order.
- Component *order* is proven significant: the same components reordered produce a different base and fail.
- An unknown or unsupported derived component raises `SignatureException` naming the component rather than being silently omitted. Silent omission is a signature-bypass primitive.
- `@signature-params` is byte-identical to the received `Signature-Input` value after the label, including parameters we do not understand.
- `composer mutation:security` at or above 80% MSI.

**Effort.** M (L counting the mutation work honestly) · **Depends on.** none · **Blocks.** T6, T23

### T6 — `fix(security): stop requiring Content-Digest on bodyless requests`

**Why.** `ContentDigestService.php:19-21` throws `'Missing Content-Digest header.'`
whenever the header is absent, unconditionally, and the signature service calls
it before anything else (line 91). RFC 9530 defines `Content-Digest` as
representation metadata for a representation that exists; a bodyless `GET` has
none. A conformant peer doing `GET /ucp/v1/orders/{id}` sends no digest and we
reject it. This is also why the GET catalog-product route (T7) can never be
signed successfully today.

**Files.** `packages/core/src/Internal/Security/ContentDigestService.php`;
`Rfc9421RequestSignatureService.php` — verify the digest only when
`content-digest` appears in the covered component list from T5, and include it
when signing only for a non-empty body;
`packages/core/tests/Unit/ContentDigestServiceTest.php`.

**Acceptance.**
- Signing a request with an empty body omits `content-digest` from both the covered list and the emitted headers.
- Verifying a bodyless GET with no `Content-Digest` and a covered list not containing `content-digest` succeeds.
- **The security half, which is the easy thing to get wrong:** a request *with* a non-empty body whose covered list omits `content-digest` is **rejected**. Otherwise an attacker strips `content-digest` from the covered list and swaps the body freely. Call this out in the PR description.
- A request with a body and a wrong digest still fails.
- `composer mutation:security` at or above 80% MSI.

**Effort.** S · **Depends on.** T5

### T7 — `fix(catalog)!: serve POST /ucp/v1/catalog/product per spec`

**Why.** `services/shopping/rest.openapi.json` defines `POST /catalog/product` at
both `2026-04-08` and `2026-08-25`;
`packages/symfony-bundle/src/Controller/CatalogController.php:45` exposes
`GET /ucp/v1/catalog/product/{id}`. An agent following the OpenAPI document gets
a 405. `generated/2026-04-08/catalog.product.request.json` already exists, so the
payload model is correct and only the HTTP binding deviates. The change also
*simplifies* the call: the controller currently passes `[]` as the payload plus
`$id` as a fourth positional argument to `ShoppingOperationRequest`, which is
precisely why request-schema validation for this operation currently does
nothing.

**Deprecation.** Ship POST with the GET route retained behind
`legacy_routes.catalog_product_get` defaulting **on**; flip the default **off**
in 0.1.0; delete in 1.0. The GET route is non-conformant so no *conformant* peer
can depend on it, but the Shopware plugin and early adopters can, and a config
flag costs one `Configuration` node. Do not keep both silently forever — that
institutionalises the deviation.

**Files.** `packages/symfony-bundle/src/Controller/CatalogController.php`;
`packages/symfony-bundle/src/Operation/ShoppingOperationExecutor.php` (stop using
the `$id` side-channel for this operation — **keep the parameter**, see T31);
`Configuration.php` (new `legacy_routes.catalog_product_get` boolean);
`packages/symfony-bundle/src/EventListener/RequestContextListener.php`
(idempotency and `isUcpRequest` now see a mutating POST); `docs/mapping-flow.md`;
`CHANGELOG.md`.

**Acceptance.** `POST /ucp/v1/catalog/product` with `{"id": "..."}` returns the
product, the body is validated against `catalog.product.request.json`, and a
malformed body returns a UCP error descriptor rather than a 500.
`GET /ucp/v1/catalog/product/{id}` returns 404 when the flag is off. New
`packages/symfony-bundle/tests/Unit/CatalogControllerTest.php` covers both
methods and both config states. `MerchantSymfonyAppKernelTest` exercises POST.

**Effort.** S · **Depends on.** none · **Blocks.** T12 (`protocol_test.py` asserts the documented route), plugin `P4`

### T8 — `fix(profile): key published payment handlers consistently with negotiation`

**Why.** `DefaultProfileBuilder.php:52` writes
`$paymentHandlers[$descriptor->name] = [$descriptor]`, so the published profile
is keyed by `name`. `DefaultCapabilityNegotiator.php:~90` iterates the remote
profile collecting `$handler->id`, intersects on **`id`**, then re-keys the result
by `name`. Two consequences: two handlers sharing a `name` but differing in `id`
silently overwrite each other at publication; and the published grouping key
differs from the negotiation key, so what a peer reads from our profile is not
what we match against. Low blast radius today only because the example app ships
one handler — which is exactly why it has gone unnoticed.

**Decision to make in the PR.** Keep `name` as the published grouping key,
matching the upstream `payment_handlers` object shape, and make the *value* a
list keyed internally by `id` so two handlers with the same `name` both survive.
Then negotiate on `(name, id)` pairs. Do **not** re-key the profile by `id` —
that changes the published wire shape.

**Files.** `packages/core/src/Internal/Service/DefaultProfileBuilder.php:48-52`;
`packages/core/src/Internal/Negotiation/DefaultCapabilityNegotiator.php:~90`;
`packages/core/src/Model/Profile/PlatformProfile.php` (document the map's key
semantics on the property); `DefaultProfileBuilderTest`,
`DefaultCapabilityNegotiatorTest`, `PlatformProfileTest`.

**Acceptance.** Two registered handlers with the same `name` and different `id`s
both appear under that `name`. Negotiation against a remote profile offering only
one of those `id`s yields exactly that one, and `NegotiatedCapabilities`
payment-handler keys match the published keys. A round-trip test —
`PlatformProfile::fromArray($builder->build(...)->toArray())` — preserves the
handler set, guarding the asymmetry from returning.

**Effort.** S · **Depends on.** none · **Blocks.** T20 (same method)

### ~~T9 — use the normative `dev.ucp.common.identity_linking` id~~ — **withdrawn, premise false**

This task was written on the claim that the SDK publishes
`dev.ucp.common.identity` while the normative name is
`dev.ucp.common.identity_linking`. **That is not true**, and the task is
withdrawn rather than left as a trap for whoever picks it up.

Verified: there is no bare `dev.ucp.common.identity` anywhere in `packages/`,
`examples/`, `docs/` or the tests. Both example apps already publish
`dev.ucp.common.identity_linking`
(`DemoIdentityLinkingCapability.php:20`, `MerchantIdentityLinkingCapability.php:30`),
which matches upstream's `schemas/common/identity_linking.json` and matches what
`SwagAgenticCommerce` publishes. Nothing disagrees.

The claim came from an exploration pass that listed capability names "seen in
code" and picked up negotiation *test fixtures* — `dev.ucp.identity.oauth`,
`dev.ucp.payment.tokenization`, `dev.ucp.shopping.loyalty` — which are arbitrary
strings chosen to exercise intersection logic, not ids this SDK publishes.

Two things it turned up that are real but are **not** this task:

- `DefaultCapabilityNegotiator::supportedOperations()` maps operations by PHP
  interface (`$capability instanceof CartCapabilityInterface`), not by capability
  name, so there is no name string to rename in the negotiator at all.
- `UcpCapability` has six cases and the example apps publish descriptors for
  `dev.ucp.shopping.discount` and `dev.ucp.shopping.payment_tokenization` that
  have none, so `discount.apply` stamps `UcpCapability::Cart` into its response
  envelope. That is defensible — the response *is* a cart, and upstream models
  discount as an extension of cart and checkout rather than a standalone
  capability — but it is a question about capability identity, which belongs with
  `T20` (versioned negotiation) and `T28` (completing the enum), not here.

**Effort.** none · **Plugin.** `P5` is withdrawn for the same reason

## Wave 2 — Conformance harness

The external oracle. It proves Wave 1 and will surface gaps this backlog does not
predict.

### T10 — `chore(examples): make the merchant app bootable as a deterministic server`

**Why.** Prerequisite for the rest of this wave.
`examples/merchant-symfony-app/public/index.php` hardcodes
`new Kernel('dev', false)`; `Kernel::baseUri()` reads `UCP_MERCHANT_BASE_URI`
(default `http://localhost:8081`) and `profileFetchingDevelopmentMode()` returns
true only in `dev`. A conformance run needs a fixed env, a fixed port, and a
clean state directory per run.

**Files.** `examples/merchant-symfony-app/public/index.php` (read
`APP_ENV`/`APP_DEBUG`); `examples/merchant-symfony-app/src/Kernel.php` (state-dir
override so the sqlite file and `JsonStateStore` can point at a scratch path);
`examples/merchant-symfony-app/README.md`.

**Acceptance.**
`APP_ENV=prod UCP_MERCHANT_BASE_URI=http://127.0.0.1:8081 php -S 127.0.0.1:8081 -t examples/merchant-symfony-app/public`
serves `/.well-known/ucp` with a 200 and a profile whose base URI matches;
running twice from a clean state directory produces byte-identical discovery
output; `MerchantSymfonyAppKernelTest` still passes.

**Effort.** S · **Depends on.** none · **Blocks.** T11, T12

### T11 — `test(conformance): add the fixture data the suite requires`

**Why.** `conformance_input.json` requires `items`, `out_of_stock_item` and
`non_existent_item`, and **every product in `ProductCatalog::PRODUCTS` has
`stock > 0`** — there is no out-of-stock fixture, so the whole out-of-stock
branch of `business_logic_test.py` and `validation_test.py` cannot run.
`test_fixtures.json` additionally needs discount codes (the app has exactly one,
`SAVE10`), known customers, shipping expectations and dynamic fulfillment
destinations (the app advertises `standard-shipping`, `express-shipping`,
`pickup-store`).

**Files.** `examples/merchant-symfony-app/src/Support/ProductCatalog.php` — add a
`stock: 0` product and **keep the four existing ids stable**, because
`MerchantExampleCoverageTest` and the kernel tests assert on them;
`.../Support/PriceCalculator.php` — deterministic shipping quote per destination;
`.../Ucp/MerchantDiscountCapability.php` — add a percentage code and an
invalid/expired code so `discount_test.py` has a negative case.

**Acceptance.** Adding the zero-stock product to a cart yields a UCP error
descriptor with the out-of-stock error code, not a 500. A known destination
produces the same shipping amount on every run. An unknown discount code produces
an error descriptor and `SAVE10` still applies. `MerchantExampleCoverageTest`
updated and green.

**Effort.** M · **Depends on.** T10

### T12 — `test(conformance): add an advisory conformance CI lane`

**Why.** The payload of this wave, and the mechanism that will keep telling the
truth as upstream moves every four months.

**Design decisions — be opinionated.**
- **Clone at a pinned tag, do not vendor.** A pytest tree in this monorepo drags Python files into `pdepend`, `composer-unused`, `phpstan` and the internal-class-reference scanner's path globs, and forks a suite whose cadence we specifically want to track. Pin the tag in a `.conformance-version` file so T27 can diff it.
- **Own the fixture config.** `tests/conformance/conformance_input.json` and `test_fixtures.json` live in *this* repo because they describe *our* example app. Do not use upstream's `test_data/flower_shop/`.
- **Do not declare capabilities we do not have.** The flower-shop default requires `dev.ucp.shopping.{checkout,order,discount,fulfillment,buyer_consent}`; this SDK has no `fulfillment` and no `buyer_consent` *capability* — `FulfillmentSelection` and `BuyerConsent` are models under checkout, and `UcpCapability` has six cases. Start with `["dev.ucp.shopping.checkout", "dev.ucp.shopping.order", "dev.ucp.shopping.discount"]` at `ucp_version: "2026-04-08"`.
- **Boot with Docker Compose**, matching the existing `docker compose run --rm php sh scripts/run-composer-in-ci.sh` idiom. Do not introduce a second, ad-hoc CI execution style.
- **Advisory plus a blocking allowlist in the same job.** Mirror the existing PHP 8.5 and BC-since-release `continue-on-error` lanes. Two steps, one clone: a blocking `pytest <allowlist>` starting with `protocol_test.py`, `validation_test.py`, `invalid_input_test.py`, `idempotency_test.py`, plus the full advisory run. Promote **per module** — an all-or-nothing flip never happens, because `ap2_test.py` and `card_credential_test.py` will fail for structural reasons long after the core is conformant.

**Files.** New `.github/workflows/conformance.yml` (a separate workflow, so its
runtime does not inflate the four-way PHP matrix); a `conformance` service in
`docker-compose.yml`; new `scripts/run-conformance.sh`;
`tests/conformance/{conformance_input.json,test_fixtures.json,README.md}`;
`.conformance-version`; new `docs/conformance.md`.

**Acceptance.** `sh scripts/run-conformance.sh` runs locally against a booted
merchant app and writes a JUnit XML the workflow uploads as an artifact. The job
summary lists pass/fail per module. The allowlisted modules are enforced by a
separate blocking step while the full run stays advisory in the same job.
`docs/conformance.md` states the promotion procedure and the current allowlist.

**Effort.** L · **Depends on.** T10, T11; materially benefits from T4-T9 landing first

### T13 — `test(conformance): promote the allowlisted modules to blocking`

**Why.** An advisory lane that stays advisory is a dashboard, not a gate. Filed
alongside T12 so the follow-through is not forgotten.

**Acceptance.** The four allowlisted modules are green on `main` for 10
consecutive runs, then the blocking step's allowlist is the enforced set and a
red module fails the PR. `docs/conformance.md` records the promotion date and
each remaining advisory module with a one-line reason.

**Effort.** S · **Depends on.** T12, Wave 1

### T14 — `test(conformance): run a strict-signature conformance pass`

**Why.** The merchant app defaults to `UCP_SIGNATURE_POLICY=log`, so a
conformance run in the default configuration exercises **zero** signature code.
Given that T4, T5 and T6 are all signature bugs, a conformance lane that does not
sign is a lane that cannot catch the class of bug that motivated the wave.

**Files.** `scripts/run-conformance.sh` (a second pass with
`UCP_SIGNATURE_POLICY=strict` and a generated key);
`examples/merchant-symfony-app/src/Kernel.php`; `tests/conformance/README.md`.

**Risk.** Requires the suite to accept a signing key. Check whether
`conformance_input.json` supports one; if not, this becomes an upstream
contribution to the conformance repo and should be scoped as such. **Flag that
early — it changes the delivery timeline.**

**Acceptance.** A `strict`-policy run rejects an unsigned request with a UCP
error descriptor and accepts a correctly signed one, proven end-to-end over HTTP
rather than in a unit test.

**Effort.** M · **Depends on.** T12, T4, T5, T6

## Wave 3 — The hard switch to `2026-08-25`

Ordering rule: dual-accepting slices land before the flip; response-field
additions cannot. Every slice is independently green.

### T15 — `chore(schema): pin the 2026-08-25 artifacts (inactive)`

**Why.** Everything in Wave 3 needs the artifacts on disk. Production wiring is
untouched and the config default stays `2026-04-08`, so this slice changes no
behaviour.

**Preparation.**
`git clone --depth 1 --branch v2026-08-25 https://github.com/Universal-Commerce-Protocol/ucp.git var/ucp-v2026-08-25`

**Sync-tool changes, exhaustively.**
- **`responseWithError()` (line 161):** `shopping/types/error_response.json` becomes `common/types/error_response.json`. Blocking — 10 call sites, plus the `catalog.product.response` `oneOf` branch at line 92.
- **Delete `mirrorDirectory($source . '/source/discovery', ...)` (line 28).** `source/discovery/profile_schema.json` is gone; `source/schemas/profile.json` arrives free via the line-27 schemas mirror. Remove the call rather than rely on the no-op: `mirrorDirectory` returns *before* `resetDirectory($target)`, so a re-sync of an existing version would preserve a stale `discovery/`.
- **Re-verify every `$defs` pointer by running the tool.** Spot-checked and surviving at `2026-08-25`: `search_request`/`search_response`, `lookup_request`/`lookup_response`/`get_product_request`/`get_product_response`, `cart.json#/$defs/checkout`, and `dev.ucp.shopping.checkout` in `discount.json`, `fulfillment.json` and `buyer_consent.json`. Detection for the remainder is `readPointer` failing loudly, which is fine, but **budget iteration time rather than treating this as one-shot.**
- **Keep `allowCartIdInsteadOfLineItems()`** (lines 59-77) and its hard `fail()` tripwire on a missing `cart_id`; update the stale path in the `checkoutExtensions()` comment (lines 143-144): `shopping/ap2_mandate.json` becomes `common/payment_ap2_mandate.json`.
- **Decide explicitly about `idRequest()`'s `additionalProperties: false`** (line 180). It would reject any new `ucp.*` request member on `cart.get`, `cart.cancel`, `checkout.get`, `checkout.cancel` and `order.get`.
- **Do not add** `location.search`, `location.lookup` or `permalink` operations. Generating schemas nothing validates produces dead JSON artifacts that no gate (`dead-code`, `coverage:gate`) can detect.
- `scripts/sync-ucp-schemas.sh` — default at line 5 and the usage clone tag at line 14 become `2026-08-25` / `v2026-08-25`; add the `--verify` invocation to the usage block.

**Acceptance.** The tool runs clean. A test loads all 30 files from
`generated/2026-08-25` and validates representative fixtures, specifically that
`cart.update.request` no longer requires `id` and that `line_item.quantity`
accepts **both** a bare integer and a measure object. The cycle-placeholder count
is at or below the T2 baseline.

**Effort.** M · **Depends on.** T2 · **Blocks.** T16-T20

### T16 — `fix(profile)!: publish keys[] and tolerate unknown JWK types`

**Why.** `2026-08-25` removes `signing_keys[]` from `profile.json` and promotes
`keys[]` (a JWK Set per RFC 7517) as the sole canonical field. It also opens the
`kty`/`crv`/`alg` vocabulary: verifiers MUST tolerate types they do not recognise
and select by `kid`, and an unsupported key MUST affect only the signature that
references it, never the whole profile.

Today `PublicSigningKey::fromJwk()`
(`packages/core/src/Model/Security/PublicSigningKey.php:56-91`) hard-throws via
`assertSupported($kid, 'kty', $keyType, 'EC')` and `expectedCurve()`, and
`PlatformProfile::signingKeys()` calls it per entry in a loop with no
`try`/`catch` — so **one OKP/Ed25519 key rejects the entire profile**, which is
exactly what the spec forbids.

**Files.**
- `packages/core/src/Model/Security/PublicSigningKey.php` — add `tryFromJwk(): ?self`; accept `kty=OKP`, `crv=Ed25519`, `alg=EdDSA`; reject private members (`d`, `p`, `q`, `dp`, `dq`, `qi`, `oth`, `k`).
- `packages/core/src/Model/Profile/PlatformProfile.php:47` — `toArray()` emits `keys`; `fromArray()` reads `keys` and falls back to `signing_keys` for one release, skipping unsupported entries instead of throwing. Note `fromArray` currently reads `signing_keys` off the *outer* payload rather than through `root()`/`entrySection()`, unlike every other section — reconcile that asymmetry.

**Keep the PHP property named `$signingKeys`.** That is the PHP-side name, not
the wire name, so the public-API snapshot and Roave stay clean — and
`SwagAgenticCommerce` constructs `PlatformProfile` positionally and reads
`->signingKeys`, so this choice means the plugin needs no change.

**Do not rename the bundle's `ucp_sdk.signing_keys` config block**
(`Configuration.php:61-70`). That is unrelated local key-management
configuration, not the wire field; renaming it would be a BC break for adopters
for no protocol reason.

**Acceptance.** A profile mixing an EC and an Ed25519 key parses, publishes both
and selects the EC key for signing. A profile whose only key has an unrecognised
`kty` parses with an empty usable-key set rather than throwing. A JWK carrying
`d` is rejected. Snapshot regenerated.

**Effort.** M · **Depends on.** T15 (for the pinned profile schema) · Order-independent relative to the flip · **Pairs with.** plugin `P6`

### T17 — `feat(model): carry the sale basis a quantity is denominated in`

**Corrected by T15.** This was written as a breaking change widening
`LineItem::$quantity` to an integer-or-measure value object. **The schema does not say that.**
`line_item.quantity` is still `type: integer` at `2026-08-25`, and its own description reads
*"Always an integer step count."*

What actually changed is the **unit** a step is denominated in:

- `item.quantity_unit` (`common/types/quantity_unit.json`) declares the sale basis; absent means
  `each`.
- `common/types/measure.json` is referenced by `unit_price` and `adjustment`, **not** by
  quantity.

So a weight-priced good is *25 steps of 100g*, not a quantity of `2.5`.
`HttpPayloadMapper.php`'s `(int) ($row['quantity'] ?? 1)` is therefore **not** the latent
data-loss bug this plan claimed -- an integer stays an integer.

**Files.** `packages/core/src/Model/Common/LineItem.php` and `Model/Catalog/Product.php` to
carry `quantity_unit`; a `UnitPrice` model carrying `amount`, `currency`, `measure`,
`reference`; `HttpPayloadMapper` to read both.

**Acceptance.** An item with a sale basis round-trips its `quantity_unit`, and a unit price
round-trips its `measure` and `reference`. Quantity arithmetic is unchanged, because quantity is
unchanged.

**No longer needed:** no `Quantity` value object, no break on `LineItem::$quantity`, no
`.bc-allowed-breaks.txt` entry, no version bump on this task's account. `T18` is now the only
deliberate break in Wave 3, so T2's allowlist remains a prerequisite -- for one task rather than
two.

**Effort.** M · **Depends on.** T15 · **No longer [BC]**


### T18 — `feat(model)!: reverse-DNS buyer consent purposes` **[BC]**

**Why.** `2026-08-25` restructures `consent` in `buyer_consent.json` from fixed
boolean fields to a dynamic map keyed by reverse-DNS identifiers
(`dev.ucp.consent.*`) returning `consent_purpose` objects with granular
segment-level opt-ins. No append expresses that.

Forward-compatible: `checkout.create/update.request` do not set
`additionalProperties: false`, so the new map validates under `2026-04-08` too
and this can land before the flip.

**Design.** New `packages/core/src/Model/Checkout/ConsentPurpose`;
`BuyerConsent(array $purposes)` with a `granted(string $purpose): bool` accessor
so call sites stay readable. `HttpPayloadMapper::toConsent()` (lines 401-408)
dual-accepts the old booleans and the new map.

**Note.** `BuyerConsent` has **no `toArray()`** today — consent is read on input
and never echoed back. If `2026-08-25` requires consent in a checkout response,
that is net-new code rather than a rename. Confirm during T15.

**Files.** `packages/core/src/Model/Checkout/BuyerConsent.php`; new
`ConsentPurpose`; `HttpPayloadMapper.php:123,140,401-408`;
`CheckoutCreateRequest.php:23`, `CheckoutUpdateRequest.php:22`;
`ShoppingOperationToolSchemas.php:89,102`.

**Obligation.** Second `.bc-allowed-breaks.txt` entry; CHANGELOG `### Breaking`;
snapshot line 218 changes.

**Effort.** M · **Depends on.** T2, T15 · **Plugin impact.** none — zero references in `SwagAgenticCommerce`

### T19 — `feat!: migrate payment and fulfillment wire keys`

**Why.** `2026-08-25` migrates payment extensions from `dev.ucp.shopping.*` to
`dev.ucp.common.payment.*` (`split_payments`, `payment_terms`, `ap2_mandates`),
relocates payment constructs to `common/types/payment.json`, splits PAN and
network token into distinct credential types, and restructures fulfillment:
config flags drop the `allows_` prefix (`multi_destination`,
`method_combinations`), `fulfillment_option.description` goes from a flat string
to a structured object, `multi_destination` goes from a map to an array of
objects, `fulfillment_available_method.type` opens from an enum to a string, and
destinations require explicit tagged `shipping`/`pickup` types.

Dual-read, so it lands before the flip.

**Files.** `packages/symfony-bundle/src/Bridge/HttpPayloadMapper.php:178-249`
(accept both namespaces; PAN vs network-token credential types);
`examples/*/src/**` capability `config` maps (drop the `allows_` prefix) and the
16 `CapabilityDescriptor` version arguments.

**No `PaymentInstrument` signature change:** `$type` is a free string and
`$credential` is `array<string, mixed>`, so the credential split lives in `$type`
values and the generated schema.

**Merchant-facing consequence.** `FulfillmentSelection`'s `$extra` absorbs the
payload restructure, so merchant adapters emitting the old fulfillment shapes
will start failing *response* validation after the flip. That is an **upgrade
note for merchants**, not SDK code. Say so in the CHANGELOG.

**Effort.** M · **Depends on.** T15 · **Pairs with.** plugin `P8`

### T20 — `feat(negotiation)!: intersect capability versions and requires ranges` **[BC]**

**Why.** `CapabilityDescriptor` already carries and publishes `version` — the gap
is that `DefaultCapabilityNegotiator.php:53` intersects capability *names* via
`array_intersect_key` and never reads it, and `supported_versions` is
advertisement only. So the SDK will happily negotiate a capability at a version
it does not implement. `2026-08-25` additionally introduces
`requires: {protocol: {min, max}, capabilities: {name: {min, max}}}` and a formal
forward-compatibility contract in which every `dev.ucp.*` entry declares version
`D` in release `D`.

**Files.**
- `packages/core/src/Internal/Negotiation/DefaultCapabilityNegotiator.php` — read the published version; exclude capabilities offered at an unsupported version and remove their operations from `operationCapabilityMap`; select the highest mutually supported version when several are offered.
- New `packages/core/src/Model/Profile/CapabilityRequirements.php` and `VersionRange.php`; new `packages/core/src/Internal/Negotiation/VersionRangeIntersector.php`.
- `packages/core/src/Model/Profile/CapabilityDescriptor.php` — trailing `?CapabilityRequirements $requires = null` (append-with-default, so the plugin's positional 5- and 6-argument construction is unaffected). Follow `fromArray()`'s existing strict `ValidationException`/`requiredString()` style.
- `packages/core/src/Model/Negotiation/NegotiatedCapabilities.php` and `NegotiationSession.php` — carry the selected version. **This is a storage change:** touch `DoctrineDbalNegotiationSessionRepository.php` and `StorageSchemaDefinition.php`.

**Acceptance.** A remote profile offering a capability at an unsupported version
yields that capability excluded and its operations removed. A profile offering
two versions selects the highest mutually supported. **Transitive exclusion is
tested explicitly** — A requires B requires C, and C absent excludes all three;
this is where a naive single-pass implementation silently under-excludes. An
open-ended range (`min` only) works. An unparseable range raises
`ValidationException` at profile-parse time. The persisted session round-trips the
selected version through both MySQL and Postgres
(`DoctrineDbalSchemaPortabilityTest`).

**Storage note for adopters.** `SwagAgenticCommerce` calls
`SchemaBootstrapper::ensureSchema()` from plugin `install()` and `update()`, so
the new column must be additive and idempotent or plugin updates break.

**Effort.** M · **Depends on.** T8, T15 · **Pairs with.** plugin `P9`

### T21 — `feat!: switch the active protocol version to 2026-08-25`

**Why.** The flip. **Keep this diff purely mechanical** — every semantic change
was already proven in T15 to T20. A semantic change buried in a 195-site rename
is unreviewable, and that is precisely how the current hardcoded-version bugs
survived.

**Files and steps.**
- `packages/core/src/Enum/UcpProtocolVersion.php` — add `case V20260825 = '2026-08-25'`. **Do not remove `V20260408`:** removing an enum case *is* a Roave break, and keeping it costs nothing because cases are invisible to the public-API snapshot. Reject it as an *active* version via the T1 container-build guard instead.
- `Configuration.php:20` — default becomes `2026-08-25`; clear `supported_versions` defaults, because advertising `2026-04-08` after a hard switch is a claim enforced by nothing.
- `DefaultProfileBuilder::schemaUrl()` (lines 109-117) already interpolates `{version}`, so it needs no change — but re-check the literal `services/shopping/` path segment against the restructured tree, which now also has `services/common/` and `services/payment-actions/`.
- Mass-update the remaining literals: 13 docs, 2 tooling sites, the test corpus.
- Remove the T18/T19 dual-accept branches, or keep them one release and deprecate.
- Delete `packages/core/resources/schema/generated/2026-04-08`; keep `pinned/2026-04-08` one release as a diffing reference, then remove.
- CHANGELOG: a `### Breaking` section naming each broken symbol and its replacement, plus a merchant-facing upgrade note covering the wire renames (fulfillment flags, payment namespaces, consent map, `keys`). Bump the release track to **0.0.6**.
- Per `docs/release-process.md:130`, the release note must state the protocol target; per lines 155-159 it must be honest about schema-validator coverage boundaries.

**Effort.** M · **Depends on.** T16-T20 · **Pairs with.** plugin `P10`, `P14`

### T22 — `test(conformance): re-point the conformance lane at 2026-08-25`

**Why.** The lane is only an oracle if it tests the version we serve.

**Files.** `tests/conformance/conformance_input.json`, `.conformance-version`.

**Note.** Upstream's flower-shop fixture still declares `ucp_version:
"2026-04-08"`, so check what the suite actually supports before assuming a
`2026-08-25` tag exists. Keep the blocking module allowlist unchanged across the
version change so any regression is attributable to the bump.

**Effort.** S · **Depends on.** T12, T21

## Wave 4 — Signature spec parity

Independent track; any time after T16.

### T23 — `feat(security): sign REST responses`

**Why.** The `2026-08-25` signatures document has a normative "REST Response
Signing" section. Today only inbound request verification and outbound webhook
signing exist (`DefaultOrderWebhookDispatcher.php:65`), so an agent cannot verify
that a checkout response came from the merchant.

**Files.** New `packages/core/src/Internal/Security/Rfc9421ResponseSignatureService.php`
reusing T5's component resolver — response signing covers `@status` plus
request-bound components via `req;`-prefixed component ids, and that
request-response binding is the part that needs care; a new interface under
`packages/core/src/Service/`; a new `ResponseSignatureListener` in the bundle,
ordered **after** `IdempotencyResponseListener` so a replayed response is signed
too (a replayed-but-unsigned response is a subtle conformance hole);
`packages/symfony-bundle/src/Bridge/UcpResponseFactory.php`; `Configuration.php`
(a `response_signing` node, `off`/`on`).

**Adopter note.** `SwagAgenticCommerce` adds its own response listeners
(`EmbeddedResponseListener` for CSP and origin, `ProfileCacheHeadersListener`).
Confirm ordering: a listener that mutates the body after signing invalidates the
signature.

**Acceptance.** A signed response's `Signature-Input` covers `@status` and the
bound request components; an external verifier validates it; an
idempotency-replayed response carries a valid signature; `response_signing: off`
emits no signature headers. `mutation:security` at or above 80%.

**Effort.** L · **Depends on.** T4, T5 · **Pairs with.** plugin `P11`

### T24 — `feat(security): support Ed25519/EdDSA signing keys`

**Why.** Prerequisite for WBA (T26), which is Ed25519-centric, and cheap once
T4's algorithm mapping exists. EdDSA needs no DER-to-raw conversion at all, which
makes it the *simpler* algorithm to get right — do not sequence it as "harder
than ECDSA".

**Files.** `packages/core/src/Enum/SignatureAlgorithm.php` (from T4);
`Rfc9421RequestSignatureService.php`;
`packages/symfony-bundle/src/Command/GenerateSigningKeyCommand.php`;
`DoctrineDbalSigningKeyRepository.php` (no schema change if the algorithm column
is a string — confirm); `ManagedSigningKey`, `PublicSigningKey`;
`packages/core/src/Internal/Security/EcPublicKeyPem.php` needs an OKP path with
no `y` coordinate.

**Dependency decision.** PHP's `ext-openssl` does not expose Ed25519, so this
needs `ext-sodium` in `packages/core/composer.json`; `require-check` will demand
it the moment `sodium_crypto_sign_verify_detached` is used. If `ext-sodium` is
unacceptable, this slice's scope changes materially — resolve that before
starting.

**Acceptance.** `ucp:signing-keys:generate --algorithm=ed25519` produces a usable
key; sign/verify round-trips; an ECDSA key and an Ed25519 key coexist in the
repository and each verifies only its own signatures; JWK export emits
`OKP`/`Ed25519`.

**Effort.** M · **Depends on.** T4

### T25 — `feat(security): RFC 7638 JWK thumbprint key ids`

**Why.** `kid` is currently an operator-chosen string defaulting to `"default"`
(`RepositoryProfileSigningKeyProvider.php:21,33`,
`GenerateSigningKeyCommand.php:23`). WBA peers key on the RFC 7638 JWK SHA-256
thumbprint, and the spec requires `kid` to equal the thumbprint for keys used in
dual-audience signatures so `UCP-Agent` and `Signature-Agent` lookups resolve the
same key.

**Scope call.** This issue is the thumbprint only — deterministic, testable,
self-contained. `Signature-Agent` resolution is T26, because it involves fetching
and caching a *remote* key directory and is a security review in its own right.
Do not bundle a hash function with an outbound-fetch feature.

**Files.** New `packages/core/src/Internal/Security/JwkThumbprint.php` — RFC 7638
canonicalisation is the whole spec (lexicographically ordered required members
only, no whitespace, base64url), and `DefaultJsonCanonicalization` may already
provide most of it; `PublicSigningKey.php`;
`Rfc9421RequestSignatureService::resolveKey()` (match on thumbprint **or**
operator kid); `GenerateSigningKeyCommand.php`,
`ShowPublicSigningKeysCommand.php`.

**Acceptance.** The thumbprint matches the **RFC 7638 section 3.1 worked example
byte-for-byte** — use the RFC's own vector, which is the one place a canonical
test vector exists, not a self-generated fixture. `resolveKey()` finds a key by
thumbprint kid and by legacy operator kid, with a test asserting the legacy path
still works. `ucp:signing-keys:show-public` displays both.

**Effort.** M · **Depends on.** T24

### T26 — `feat(security): resolve Signature-Agent and honour tag="web-bot-auth"`

**Why.** The remaining half of WBA interop. The spec requires a WBA-shape signer
to send `Signature-Agent` **alongside** `UCP-Agent` (additive, not a
replacement), to cover the `signature-agent` component with `;key="<label>"`
matching the dictionary member key, and to include `tag="web-bot-auth"`. Today
`Signature-Agent` appears nowhere in the codebase and `tag` is neither produced
nor enforced (the parser would stash it, but nothing reads it).

**Files.** `Rfc9421RequestSignatureService.php` (`tag` parameter, the
`signature-agent` covered component with its `;key` parameter); a new agent
key-directory fetcher alongside
`packages/core/src/Internal/Service/HttpAgentProfileFetcher.php`, reusing
`UrlSafetyValidator`; a new repository interface plus a DBAL adapter for the key
cache, following the six existing `DoctrineDbal*Repository` classes and
`StorageSchemaDefinition`.

**Acceptance.** A `Signature-Agent` host outside `allowed_profile_hosts` is
refused (an SSRF test mirroring `UrlSafetyValidatorTest`). A cached agent key is
reused without a second fetch. `tag="web-bot-auth"` is accepted and an
unrecognised tag is rejected rather than ignored. Verification fails when the
`Signature-Agent` dictionary member key does not match the signature's `;key`
parameter. `mutation:security` at or above 80%.

**Effort.** L · **Depends on.** T25

## Wave 5 — Sustainability

### T33 — `fix(negotiation)!: refuse only when the capability intersection is empty`

**Found by T12 on its first run, not predicted.** 59 of the 63 conformance failures report the
same thing:

```
400 Requested operation is not included in the negotiated capability intersection.
```

**Why.** `ShoppingOperationExecutor::assertNegotiated()` refuses an operation whose capability
is not in the negotiated intersection. The conformance suite's mock agent profile declares
exactly one capability, `dev.ucp.shopping.order`, so every checkout, cart and catalog call is
refused.

The specification does not appear to require this. It defines a negotiation failure as the
intersection being **empty** — *"the provided profile is valid but capability intersection is
empty or versions are incompatible"* — and the intersection here is not empty. What the
intersection governs, per *Response Capability Selection*, is which capabilities a business
declares in `ucp.capabilities`, not which requests it will answer. A platform is required to
advertise a profile; nothing says it must enumerate every capability it intends to call.

**Size, measured.** Relaxing the gate to fail only on an empty intersection takes the suite from
1 passing to 5. So it is the **first** blocker rather than the whole chain — the other 58 fail
again further along — but nothing else can be assessed until it moves. Expect the next layer of
findings immediately after.

**Files.** `packages/symfony-bundle/src/Operation/ShoppingOperationExecutor.php`
(`assertNegotiated()`); `packages/core/src/Internal/Negotiation/DefaultCapabilityNegotiator.php`
if response-capability selection needs to narrow independently;
`packages/symfony-bundle/tests/Unit/ShoppingOperationExecutorValidationTest.php`.

**Decide in the PR.** Whether per-operation enforcement stays available behind configuration.
It is the safer behaviour in one respect — answering a capability the peer never declared means
it may not parse the response — but it is stricter than the spec and it is not what the
conformance suite expects. Note that this was added deliberately (CHANGELOG 0.0.1,
"request-time negotiation enforcement"), so this is a reversal rather than an oversight, and the
reasoning behind it deserves finding before it is undone.

**Acceptance.** An operation whose capability is outside a non-empty intersection is served, and
its response declares only the capabilities that are both negotiated and relevant. An empty
intersection still fails with `capabilities_incompatible`. The conformance lane moves from 1
passing to at least 5, recorded in `conformance.md`.

**Effort.** M · **Depends on.** T12 · **Blocks.** T13

### T32 — `docs: bring the documentation in line with what shipped`

**Why.** Waves 0 to 2 changed behaviour the docs still describe the old way. Each slice updated
what it directly touched — `extension-contract.md` in T31, the schema README in T2, the merchant
README in T10 — but nothing has read the set as a whole, and several documents describe a
protocol surface that has since moved.

**Files, and what is stale in each.**

- `security-model.md` — signatures changed shape three times (fixed-width ECDSA, registry
  algorithm names, base built from the peer's covered components, `Content-Digest` now
  conditional). This is the document most likely to be wrong and the one most likely to be read.
- `production-operator-checklist.md` — new configuration exists (`legacy_routes.catalog_product_get`),
  and idempotency is now decided per operation rather than per HTTP method.
- `getting-started.md` — carries protocol-version literals and predates the version plumbing in T1.
- `mapping-flow.md` — `catalog.product` moved to `POST` and now carries a body it previously could not.
- `platform-adapters.md`, `storage-adapters.md` — check against the promoted public API from T31.
- `release-process.md` — the pre-tag checklist should mention `sync:verify` and the conformance lane.
- `README.md` — the route list and the "in scope" summary.
- `full-ucp-parity-plan.md` — already repointed in T30, verify it stayed accurate.

**Acceptance.** No document describes a route, header or algorithm the SDK no longer serves. A
reader following `getting-started.md` end to end reaches a working, conformant setup.

**Effort.** M · **Depends on.** the wave it documents; best run once per wave rather than per slice

### T27 — `ci(spec-drift): detect new upstream releases and pinned-schema divergence`

**Why.** Nothing in the repo notices that upstream moved, and the target moves
roughly every four months. This job is what makes this backlog repeatable rather
than a one-time catch-up. **Do it early — it is Wave 2 work in spirit, not Wave
5.**

**Two independent checks in one scheduled workflow.**
1. **Release detection** — compare the newest release tags of `Universal-Commerce-Protocol/ucp` and `Universal-Commerce-Protocol/conformance` against `UcpProtocolVersion` and `.conformance-version`; open or update a single tracking issue.
2. **Reproducibility** — clone the *pinned* tag, re-run `tools/sync-ucp-schemas.php`, and `git diff --exit-code packages/core/resources/schema/generated/`. This catches both an upstream retag and local hand-edits to generated files, and it is the check that actually protects the pinned tree.

**Files.** New `.github/workflows/spec-drift.yml` (scheduled weekly plus manual);
new `scripts/check-schema-drift.sh`; `tools/sync-ucp-schemas.php` must be
idempotent — a non-deterministic key order would make check 2 permanently red.

**Acceptance.** Check 2 is green on `main` today. A deliberately modified
generated schema fails it. A simulated newer upstream tag opens exactly one issue,
and updating it does not open a second.

**Effort.** M · **Depends on.** none

### T28 — `refactor: route production protocol-version literals through the enum`

**Why.** The literal `2026-04-08` appears 195 times across 54 files, but roughly
85% of that is test data where a literal is the *correct* thing to write — a test
asserting against `UcpProtocolVersion::current()` asserts nothing. **Scope this
to production code only.**

**Files.** `packages/core/src/Enum/UcpProtocolVersion.php` (add
`current()`/`isSupported()`); the 16 example `Demo*`/`Merchant*` capability
descriptors; `NegotiationSession.php`; `tools/sync-ucp-schemas.php`;
`README.md`, `AGENTS.md`, `docs/getting-started.md`,
`packages/core/resources/schema/README.md`. Also completes `UcpCapability`
(currently 6 cases, while `discount`, `payment` and `identity` exist only as raw
strings) and removes the remaining raw `dev.ucp.*` literals — the tail of T9.

**Acceptance.** A source-tree test asserts no `2026-` literal in
`packages/*/src` outside `UcpProtocolVersion`, using the existing
`SymfonyInternalApiBoundaryTest` /
`tools/check-internal-class-references.php` idiom. Test-data literals untouched
and green. Bumping `current()` requires no `src/` edit.

**Effort.** S · **Depends on.** T21

### T29 — `test: close the controller and support-class coverage holes`

**Why.** Six controllers have no dedicated tests — `Cart`, `Catalog` (delivered
by T7), `Checkout`, `Order`, `OAuth`, `Embedded` — and neither do
`StorageCleanupService`, `OriginMatcher`, `ConnectionFactory`, five of the six
`AdapterBacked*Capability` wrappers, most `Model/*` DTOs, or `Event/*`.
Conformance (Wave 2) covers happy-path black-box behaviour; these cover the error
paths it will not reach: malformed idempotency keys, disabled transports, origin
mismatches, OAuth state mismatch.

**Acceptance.** Each named class has a test; `EmbeddedController` covers origin
rejection via `OriginMatcher`; `OAuthController` covers state mismatch;
`coverage:gate` threshold raised in the same PR, or the tests are decoration;
phpstan level 7 clean.

**Split into three PRs.** A 30-file test PR does not get reviewed.

**Effort.** M · **Depends on.** T7

### T30 — `docs: replace the stale full-UCP-parity plan`

**Why.** `docs/full-ucp-parity-plan.md` is 33 lines about transport metadata and
says nothing about schema structure or version migration, yet it is the document
whose name promises to be the gap statement.

**Acceptance.** It points at this document and retains only the still-valid
MCP-proxy architectural decision. `docs/README.md` lists this document.

**Effort.** S · **Depends on.** none

---

## Dependency order

```
T1 → { T2, T3, T31 }
T2 → Wave 1: T4, T7, T8, T9 (parallel);  T5 → T6
T1 → Wave 2: T10 → T11 → T12 → T13 ;  T12 + T4 + T5 + T6 → T14
T2 → T15 → { T16 ∥ T17 ∥ T18 ∥ T19 ∥ T20 } → T21 → T22
T4 → T23 ;  T4 → T24 → T25 → T26
T27, T29, T30 : any time (T27 early) ;  T21 → T28
T12 → T33 → T13 ;  T32 : once per wave, after the wave lands
```

## Execution model

**30 slices become roughly 32 PRs.** One per issue, except T29 which is three.
There is deliberately **no single PR containing everything**:

1. `scripts/bc-check.sh` is blocking with no ignore mechanism, so until T2 lands the allowlist, T17 and T18 are red by construction. A mega-PR would carry a permanently red required check.
2. The `additionalProperties: false` split means input-dual-accepting slices must precede the flip and response-field additions must follow it. Collapsing them removes the ordering that makes each half provable.
3. T21's value is being a mechanical diff because T15 to T20 were each proven green first.

The realistic floor if compressed is around 24 PRs (merging T4+T5+T6, T8+T9,
T13 into T12). Not recommended for the signature trio: the 80% MSI gate makes a
large `Internal/Security` diff expensive to get green, and a failed mutation run
gives no hint which of the three changes caused it.

### Lanes

Parallelism is bounded by **file collisions**, not by the dependency graph. Three
files are contended by design: `Rfc9421RequestSignatureService.php`
(T4/T5/T6/T23-T26), `DefaultCapabilityNegotiator.php` (T8/T9/T20), and
`HttpPayloadMapper.php` (T17/T18/T19). Assign by ownership:

| Lane | Owns | Tasks in order |
|---|---|---|
| A — Signatures | `Internal/Security/*`, `EcdsaSignatureCodec`, `SignatureAlgorithm` | T5 → T4 → T6 → T23 → T24 → T25 → T26 |
| B — Profile and negotiation | `DefaultCapabilityNegotiator`, `DefaultProfileBuilder`, `CapabilityDescriptor`, `PlatformProfile`, `PublicSigningKey` | T8 → T9 → T16 → T20 |
| C — Transport and mapper | `Controller/*`, `HttpPayloadMapper`, `ShoppingOperationExecutor`, request/response models | T7 → T17 → T18 → T19 |
| D — Build and tooling | `tools/`, `scripts/`, `.github/workflows/`, composer scripts | T2 → T31 → T15 → T27 |
| E — Examples and conformance | `examples/`, `tests/conformance/`, the conformance workflow | T10 → T11 → T12 → T13 → T14 |
| F — Docs and coverage | docs, test-only PRs | T3, T28, T29a-c, T30 |

Serial gates belonging to no lane: **T1** before anything; **T2** before T17/T18;
**T15** before Wave 3 absorption; **T21** as the join point.

**Peak useful concurrency is 5 lanes, typically 3 to 4.**

### Parallel-agent constraint

Agents working in the **same working tree interfere**: `composer qa` writes to
`var/reports/`, so concurrent `mutation:gate` and `coverage:gate` runs clobber
each other and produce false failures; `scripts/bc-check.sh` builds throwaway git
repos in temp directories and compares **refs, not the working tree**, so an
uncommitted lane reads as clean; and `tools/check-public-api-snapshot.php`
regenerates before comparing, so two lanes race on
`tools/public-api-snapshot.txt`.

Use **one git worktree per lane**, each with its own `vendor/` install and its own
container. `composer qa` is heavy (mutation plus pdepend), so lanes should run
`composer test` in the inner loop and the full gate only before pushing.

## Verification

Validation is layered, and the layers are not interchangeable. The existing suite
can only prove we match what we wrote; only layers 3 and 4 can prove the SDK
interoperates.

| Layer | What it proves | Mechanism |
|---|---|---|
| 1. Per-slice gate | The change is internally consistent and does not regress | `composer qa` per PR |
| 2. Cross-slice invariants | The three version sites agree; generated schemas are reproducible; the public surface changed only where declared | T1's drift-guard test, T2's `sync:verify`, the public-API snapshot, `bc-check.sh` plus its allowlist |
| 3. **External oracle** | A conformant peer can actually talk to us | T12-T14: the upstream conformance suite against a live merchant app, including a strict-signature pass |
| 4. **External fixtures** | Our crypto matches the RFC, not our reading of it | T4/T5 against an independent RFC 9421 implementation; T25 against the RFC 7638 section 3.1 worked example |

Layer 3 is the one that closes the loop. Layers 1 and 2 were fully green while the
SDK emitted DER signatures no peer could verify — that is the whole point.

**Definition of done for the effort:** the conformance lane runs against
`2026-08-25` (T22) with the blocking module allowlist enforced (T13) and the
strict-signature pass green (T14), and T27 is scheduled so the *next* upstream
release arrives as a tracking issue rather than a surprise.

### Commands

Everything runs through the container:

```
docker compose run --rm php composer qa                  # the full gate
docker compose run --rm php composer test                # faster inner loop
docker compose run --rm php composer public-api:check
docker compose run --rm php composer mutation:security    # any Wave 1 or Wave 4 slice
sh scripts/bc-check.sh HEAD^ HEAD                        # commit first: it compares refs
sh scripts/run-conformance.sh                            # after T12
```

Slice-specific:

- **T1** — the drift-guard test fails if any of the three version sites disagree; set `ucp_sdk.version` to a bogus value and confirm container compilation fails.
- **T2** — `sync:verify` green on `main`; hand-edit a generated schema and confirm it fails; add an allowlist line and confirm `bc-check` accepts exactly that break.
- **T4/T5/T6** — verify against fixtures from an *external* RFC 9421 implementation, never our own signer.
- **T7/T10/T11** — boot the merchant app and `curl` `/.well-known/ucp` and `POST /ucp/v1/catalog/product`.
- **T12-T14** — `sh scripts/run-conformance.sh` locally; inspect the JUnit artifact and the per-module summary.
- **T15** — re-run the sync tool from a fresh `v2026-08-25` clone; confirm a clean, idempotent regeneration and a placeholder count at or below baseline.
- **T20** — `DoctrineDbalSchemaPortabilityTest` against both the MySQL 8.4 and Postgres 16 compose services.
- **T21** — end-to-end: discovery advertises `2026-08-25`, response envelopes carry `2026-08-25`, and the conformance lane still passes at the new version (T22).

## Related

- Plugin-side backlog: `SwagAgenticCommerce` repository, `docs/ucp-sdk-integration-backlog.md`
- [full-ucp-parity-plan.md](full-ucp-parity-plan.md) — superseded by this document, retains the MCP-proxy decision
- [release-process.md](release-process.md) — the pre-tag checklist a version bump must follow
- Upstream spec: <https://github.com/Universal-Commerce-Protocol/ucp>
- Upstream conformance suite: <https://github.com/Universal-Commerce-Protocol/conformance>
