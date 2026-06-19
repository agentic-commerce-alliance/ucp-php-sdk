# Production Operator Checklist

Use this checklist before a production-ready or open-source release, and before enabling the SDK in a live integration.

The checklist is evergreen. Version-specific caveats still belong in the GitHub Release for the tag being prepared.

## Runtime Configuration

- Configure `allowed_profile_hosts` with the exact platform profile hosts the SDK may fetch.
- Configure `allowed_agent_domains` with the exact agent or embedded origins allowed to call browser-facing surfaces.
- Use `signature_policy: strict` for production unless the release notes explicitly document a temporary exception.
- Keep `profile_fetching_development_mode` disabled outside local development.
- Enable non-REST transports only when the adopter has configured and tested them for that deployment.
- For MCP, provide an explicit `transport_endpoints.mcp` value. The shared SDK publishes metadata only and does not provide a default `/ucp/mcp` runtime endpoint.

## Mutating Requests And Idempotency

- Require `Idempotency-Key` for production mutating routes unless a specific operation is documented as safe to retry without replay protection.
- Treat missing idempotency on cart, checkout, tokenization, payment, or order mutation traffic as an integration defect.
- Confirm the configured idempotency TTL is long enough for the longest expected client retry window.
- Confirm stored idempotent response bodies fit within `idempotency.max_stored_response_bytes`.
- Do not rotate `%kernel.secret%` for the default DBAL storage adapter without migrating or purging encrypted idempotency response rows.

## Storage And Cleanup

- Bootstrap the default DBAL schema during install, update, deployment, or startup before using the default repositories.
- Schedule regular cleanup with:

```bash
docker compose run --rm php php bin/console ucp:storage:cleanup
```

- Confirm cleanup covers expired OAuth state, idempotency rows, negotiation sessions, cached platform profiles, signature replay nonces, and retired signing keys beyond the configured retention window.
- Confirm `%kernel.secret%` rotation impact before rotating secrets in deployments that use default encrypted DBAL storage. Rotating it without migration invalidates encrypted OAuth refresh tokens and idempotency response rows.

## Signing Keys

- Generate at least one active signing key before publishing live discovery documents or sending live webhooks.
- Document the key owner, tenant scope if any, creation date, and planned retirement date.
- Rotate keys deliberately: add a new active key, publish it, wait for consumers to refresh discovery, then retire old keys after the overlap window.
- Retain retired keys only for the verification window required by consumers, then rely on cleanup to purge old retired keys.
- Confirm key generation, listing, and public-key output commands work in the deployment environment.

## Webhook Publishing

- Allowlist webhook target hosts at the integration or platform layer before enabling outbound order webhooks.
- Reject unsafe webhook targets, including non-HTTP(S) schemes, userinfo URLs, blocked metadata hosts, private address ranges, and unexpected ports.
- Confirm webhook payloads fit within configured request body limits before dispatch.
- Confirm webhook response bodies are capped through the configured webhook response limit.
- Treat `408`, `425`, `429`, and `5xx` responses as retryable unless the host integration documents a stricter policy.
- Confirm webhook dispatch uses the correct active signing key for the tenant or deployment.

## AP2 Boundary

- Treat AP2 merchant authorization as unsupported by default in the shared SDK.
- Do not claim full AP2 credential-stack support unless a host app, platform plugin, or separate package replaces the default unsupported verifier.
- Document AP2-related integration support in the platform-specific package, not in the shared SDK.

## Alpha And Release Caveats

- Before tagging a pre-`1.0` release, list remaining alpha caveats in the GitHub Release notes.
- Call out any unresolved production-readiness blockers, unsupported transports, storage limitations, schema coverage boundaries, or temporary compatibility shims.
- Keep those caveats in the GitHub Release for the tag instead of adding version-specific repo files.

## Release Gate Evidence

Before tagging, make sure the release has evidence for:

- profile JSON round-trip signature verification
- SSRF and production allowlist behavior proving unsigned requests cannot fetch arbitrary profiles
- MySQL and Postgres DBAL schema smoke coverage, or explicit documentation that the default adapter is SQLite-only
- duplicate idempotency claim and concurrency behavior for mutating routes
- strict-mode behavior for public well-known discovery endpoints
- request and response validation, or explicit no-schema decisions, for every UCP operation exposed through REST and A2A
- embedded full-origin matching
- REST malformed JSON and scalar JSON handling
- full QA and Composer metadata validation as described in [release-process.md](release-process.md)
