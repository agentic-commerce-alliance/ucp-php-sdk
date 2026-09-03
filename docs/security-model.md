# Security Model

The shared SDK currently provides the common protocol security pieces needed by REST integrations.

## Current Pieces

- `Content-Digest` support
- RFC 9421 style request signing through `RequestSignatureServiceInterface`
- deterministic JSON output for SDK-local canonicalization through `DeterministicJsonInterface`
- replay protection through `SignatureReplayGuardInterface`
- managed signing key generation and public JWK projection
- discovery signing key publishing
- idempotency handling
- remote platform-profile fetch allowlists
- request body size enforcement before signature verification and validation
- an unsupported-by-default merchant-authorization hook that host apps can replace

## Signature Wire Format

What goes on the wire, because getting any of it wrong produces a signature that verifies
locally and nowhere else.

- **ECDSA signatures are fixed-width `r || s`** — 64 bytes on P-256, 96 on P-384 — as RFC 9421
  section 3.3.1 requires. `openssl_sign()` returns DER, which is a different encoding of the
  same signature, so it is converted on the way out and back on the way in.
- **`Signature-Input` names the algorithm from RFC 9421's registry** (`ecdsa-p256-sha256`), not
  by its JWA name (`ES256`). Managed keys still *store* the JWA name, which is what a JWK's
  `alg` member carries.
- **Verification rebuilds the signature base from the components the peer covered**, in the
  order it listed them. A component this SDK cannot resolve is refused rather than skipped: a
  base that silently omits a covered component confirms nothing about it.
- **`@signature-params` is reproduced byte for byte** from the received header. Re-serialising
  it would reorder parameters and drop unrecognised ones, and either changes the base.
- **`Content-Digest` is required exactly when the signature covers it.** It is representation
  metadata (RFC 9530), so a bodyless `GET` has none. A request that carries a body whose covered
  components omit `content-digest` is refused — otherwise dropping it from the list would leave
  the body unattested while the signature still verified.

For one release, verification also accepts DER-encoded signatures and the JWA algorithm name,
so a peer running an older build of this SDK keeps working.

## Negotiation Failures Are Not All Transport Errors

The spec assigns different statuses by kind, and this SDK follows them:

| Code | REST status | Why |
| --- | --- | --- |
| `capabilities_incompatible` | `200` | A business outcome — the handler ran on the inputs it was given and reported what it found. Carried inside a UCP response with `ucp.status: "error"`. |
| `version_unsupported` | `422` | A transport error: the platform's profile version is not one this business can use. |

## Key Lifecycle

Managed keys are stored through `ManagedSigningKeyRepositoryInterface`.

Public discovery keys are derived from managed keys through `SigningKeyManagerInterface`.

The bundle ships commands for:

- generating keys
- listing keys
- showing public discovery keys

Webhook publishing does not generate keys on demand anymore.
Operators should create keys ahead of time and rotate them deliberately.

## Replay And Idempotency

- signature replay state is stored separately from idempotency records
- request idempotency is only applied to mutating requests
- the default response listener aborts pending idempotency state on `5xx`
- idempotent response bodies are encrypted at rest in the default storage adapter
- unreadable encrypted idempotency response bodies fail closed instead of falling back to plaintext JSON
- changing `%kernel.secret%` without migrating default storage invalidates encrypted idempotency rows
- oversized idempotent responses are marked non-replayable instead of being re-executed silently

## OAuth State

- authorization codes are stored as SHA-256 hashes, not in plaintext
- refresh tokens are encrypted at rest in the default storage adapter
- unreadable encrypted refresh tokens fail closed instead of falling back to stored plaintext
- changing `%kernel.secret%` without migrating default storage invalidates encrypted refresh tokens
- authorization codes are single-use and short-lived by default

## Cleanup

The bundle exposes `ucp:storage:cleanup` to purge expired OAuth state, idempotency rows, negotiation sessions, cached platform profiles, signature replay nonces, and retired signing keys beyond the configured retention window.

## AP2 Boundary

The shared SDK only provides helper-level merchant-authorization verification hooks.

The default shared implementation does not verify merchant authorization cryptographically.
It only reports that the flow is unsupported unless a host app or platform plugin replaces it.

It does not provide:

- a full SD-JWT VC stack
- KB verification
- a full AP2 mandate package

That work belongs in a separate package or in a platform plugin.
