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
