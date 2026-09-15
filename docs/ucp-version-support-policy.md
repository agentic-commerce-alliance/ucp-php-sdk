# UCP version support policy

Decision of record: **this SDK serves exactly one UCP protocol version per release line, the
newest one.** `0.0.6`, the current release, serves `2026-08-25`. There is no runtime multi-version support, and
`supported_versions` does not create any. The decision is revisited on measured data, not on
principle; the measurement and the threshold are defined at the end of this document.

This supersedes the one-line "hard switch" entry in
[ucp-2026-08-25-upgrade.md](ucp-2026-08-25-upgrade.md), which remains the historical record
of the bump itself.

## Why this document exists

`0.0.6` switched from `2026-04-08` to `2026-08-25` outright and broke adopters: `keys[]`
replaced `signing_keys[]`, the payment extensions moved namespace, fulfillment was
restructured. The question that followed was whether the SDK should serve several UCP versions
at once, and if not, why not. Answering it well means correcting one assumption and writing down
several facts that were not in any one place.

## What the specification guarantees

UCP does **not** promise additive versioning across releases. It promises additive changes
*within* a dated snapshot and an **exact match across** snapshots. From
`ucp.dev/latest/specification/overview/`:

> the match is exact: an older date is available only when the Business advertises it in
> `supported_versions`.

The specification enumerates a breaking-change class for exactly this case. `2026-08-25`
renamed fields, moved a namespace and restructured an object; that is the class behaving as
designed, not the specification breaking its own rules. Reading UCP as "additive, so an older
platform will still work against a newer business" is the assumption this document corrects.

## How negotiation actually works

Version selection happens at discovery time, not per request:

1. The platform fetches the business profile at `/.well-known/ucp`. The profile names one
   `ucp.version` and, optionally, a `supported_versions` map.
2. Each `supported_versions` entry maps an **older date to a self-contained profile URI**, with
   its own capabilities, endpoints and signing keys. Serving an older version means serving a
   whole second profile at a second location, not flipping a flag on the first one.
3. The platform picks the profile whose version it speaks and sends every request to the
   endpoints that profile lists, identifying itself with `UCP-Agent: profile="…"`.

There is no protocol version header. `UCP-Agent; version="…"` is **not** in the specification;
it is proposed in upstream PR #793. This SDK reads it anyway, in
`DefaultHttpRequestContextFactory`, and refuses a declared version it does not serve before the
profile is fetched. That is a courtesy to a platform that announces itself, not a negotiation
mechanism. Advertising several versions inside one profile is explicitly reserved for
third-party extensions and is not how the core protocol does it.

The consequence for this SDK: a platform on `2026-04-08` that reaches the `2026-08-25`
endpoints has already made a mistake the protocol says it should not make. The right answer is
`422 version_unsupported`, not a best-effort reply in shapes it will misread.

## What upstream does

- **Official Python SDK.** `0.5.x` serves `2026-08-25`; `0.4.x` serves `2026-04-08`. One
  version per release line.
- **Official JS SDK.** Code-generated from one dated schema set per release. One version per
  release line.
- **Upstream conformance suite.** Takes a single `ucp_version` and pins a single SDK ref. It
  cannot run a version matrix, so a multi-version implementation would have no upstream way to
  prove it conforms on more than one of them.

The ecosystem is single-version by construction. This SDK matching it is the unsurprising
choice; departing from it would be the one that needs justifying.

## Adoption, and the Shopify contrast

Per `ucpchecker.com/specs`, over 17,197 verified stores as of September 2026:

| Version | Share of stores |
|---|---|
| `2026-08-25` | 61 % |
| `2026-04-08` | 38 % |
| January 2026 versions | about 26 stores |

Shopify, which accounts for roughly 99 % of those stores, advertises `2026-01-23` and
`2026-04-08` in `supported_versions` alongside the newest. So the market leader serves three
versions and this SDK serves one. That is the strongest argument for multi-version support and
it deserves to be stated plainly.

It is also weaker than it looks. Store-side distribution says nothing about which version the
**agents** pin, and the agents are the traffic. A business that serves only the newest version
loses nothing if every agent it cares about speaks it, and loses a great deal if a major agent
does not. That number is unmeasured: UCP platforms advertise their version only indirectly,
through the profile their `UCP-Agent` header points at, and there is no public registry of it.
Until the change described below, this SDK read that signal on every request and discarded it.

## Decision

Serve exactly one UCP version per SDK release line, the newest, matching upstream's own SDKs.

Reasons, each verifiable:

1. **Pre-1.0, and full conformance is not claimed.** `CHANGELOG.md` says so under `0.0.6`.
   Breaking releases are permitted with a changelog entry and a version bump; that is the
   contract adopters have today.
2. **Every official UCP SDK is single-version.** See above.
3. **The upstream conformance suite cannot run a version matrix.** A second served version
   would be a version this project could not prove conformant with the only check it did not
   write itself.
4. **The specification's own multi-version mechanism is a second profile, endpoint set and
   key set**, not a configuration flag. Its cost is the cost of the alternative below, and
   the specification does not offer a cheaper one.
5. **Adoption is already majority-newest and rising.** 61 % of stores on `2026-08-25` within
   weeks of the release.
6. **The domain model is `final` and `readonly` throughout.** Of 66 model files under
   `packages/core/src/Model`, 63 are `final` and 62 use `public readonly` properties. A second
   wire shape cannot be a nullable field on the same object; it is a second mapper layer.

## Cost of the alternative

An informed revisit needs the price of serving N−1. Today the protocol version is
**container-scoped**, and serving two versions requires it to become **request-scoped**:

- `UcpSdkExtension` binds the version into `UcpSdkConfiguration`, into `RuntimeConfiguration`
  and into the `GeneratedSchemaValidator` schema directory at container build.
- `SchemaDirectoryLocator::generated()` resolves one schema tree from that version, and fails
  container compilation if the tree is absent.
- `ShoppingOperationExecutor::response()` stamps the configured version into every envelope.

Beyond those three seams:

- **A per-version wire mapper layer.** The Model Context Protocol's TypeScript and Python SDKs
  are the prior art: they keep `wire/rev<date>/` trees and speak two protocol eras from one
  release. They are the only protocol SDKs in this space that do, and the cost shows in their
  repositories.
- **A restored `resources/schema/generated/2026-04-08` tree**, kept in sync by
  `scripts/sync-ucp-schemas.sh` alongside the current one.
- **A second profile route and a second endpoint set**, because that is what a
  `supported_versions` entry points at.
- **On the plugin side**, about 13 files under `src/` in `SwagAgenticCommerce` that map
  Shopware data to UCP shapes, each of which would need a version branch or a twin.

None of that is impossible. All of it is a second implementation of the wire, maintained for
as long as the older version has traffic, with no upstream tooling to prove it correct.

## Testing strategy

This belongs here whether or not N−1 is ever built, because it is the answer to "how do we
avoid doubling the test suite":

- **The full suite runs at `UcpProtocolVersion::current()` only.** Business logic is never
  duplicated across versions.
- **Per-version coverage, if a second version is ever served, is limited to two things.**
  Golden request and response fixtures validated against the published dated JSON Schemas
  (upstream ships the `ucp-schema` Rust validator for exactly this, and the per-version schemas
  are live at `ucp.dev/{version}/schemas/...` for all four releases), and the conformance lane
  run once per served version on the existing weekly schedule rather than per pull request.
- **Drift guards stay single-sourced.** `ProtocolIdentifierSourceTest` fails on any
  `YYYY-MM-DD` literal in production source outside the enum; that rule does not relax for a
  second version, it gains a second enum case.

## What changes alongside this policy

Two things, neither of which changes what is served. Both are code changes that ship with the
next SDK release, whenever that is cut; this document does not decide the release.

**`supported_versions` no longer widens the accept list.** `ShoppingOperationExecutor` used to
accept a platform whose profile version appeared among the `supported_versions` keys. Those
keys name versions served by *other* profiles at *other* URIs; treating them as answerable here
meant an operator who listed `2026-04-08` had `2026-04-08` platforms accepted at the
`2026-08-25` endpoints and answered in `2026-08-25` shapes. That is the silent disagreement the
version check exists to refuse, and the upgrade document predicted it as "a claim enforced by
nothing". The accept list is now exactly the configured version. The configuration node stays,
because pointing at a separately deployed older release is legitimate; its keys are validated
as `YYYY-MM-DD` and its values as non-empty URIs.

**Every version negotiation is observed.** `Ucp\Sdk\Event\VersionNegotiationObservedEvent`
is dispatched on every shopping operation, accepted or refused, with the version the platform
named, the version served, the platform's profile URI and the outcome. A refusal on the
`UCP-Agent; version=` parameter is observed too, since it never reaches the executor. A
listener that counts these answers the unmeasured question above.

## Revisit trigger

Once the observation above has run in production for one release cycle, this decision is
revisited if **either**:

- observed distinct agent profile versions below `UcpProtocolVersion::current()` exceed
  **5 % of negotiations**, or
- **any single named agent client this project cares about** is observed on an older version.

Measured as the `Rejected` share of `VersionNegotiationObservedEvent` counts, grouped by
observed version and profile host. Below the threshold, the next spec release is another hard
switch with a changelog entry. Above it, the cost section above is the estimate to argue with.

## Related

- [ucp-2026-08-25-upgrade.md](ucp-2026-08-25-upgrade.md) — the bump this policy grew out of
- [conformance.md](conformance.md) — the upstream suite and its single `ucp_version`
- [release-process.md](release-process.md) — how a version bump is released
- `SwagAgenticCommerce/docs/ucp-version-support.md` — the plugin-side note for integrators
