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
- negotiation-gated AP2 mandate verification (fail-closed) and ES256 `ap2.merchant_authorization` signing

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

The shared SDK implements the AP2 mandates extension end to end at the protocol layer:

- it advertises the `dev.ucp.shopping.ap2_mandate` capability (extending checkout) so AP2 activates only through capability negotiation — an `ap2` member on a session that did not negotiate the capability is rejected (`ap2_not_negotiated`);
- a negotiated session must supply `ap2.checkout_mandate` on `checkout.complete`, or the request is rejected with `mandate_required`;
- registered `Ap2CheckoutMandateVerifierInterface` services verify the mandate against the current checkout terms, and the completion is passed the verified checkout snapshot so adapters can refuse to complete terms that changed after verification (`mandate_scope_mismatch`);
- every checkout response of a negotiated session is signed with an ES256 detached JWS as `ap2.merchant_authorization`.

The SDK does **not** ship a default mandate verifier, so it fails closed: if no registered verifier supports the presented mandate the completion is rejected (`mandate_format_unsupported`) rather than accepted unverified. Verifiers combine with OR semantics — at least one supporting verifier must accept the mandate.

The credential-stack internals a verifier needs are out of scope for the shared SDK:

- a full SD-JWT VC stack
- key-binding (KB) verification

That work belongs in the verifier implementation, in a separate package or a platform plugin.
