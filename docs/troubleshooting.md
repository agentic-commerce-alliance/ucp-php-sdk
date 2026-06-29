# Troubleshooting

Common failure modes when integrating the UCP PHP SDK, with the usual cause and
fix. See also [getting-started.md](getting-started.md) and the
[production operator checklist](production-operator-checklist.md).

## "no such table" / missing-relation errors on the first request

**Cause:** The default DBAL repositories do not create their schema on
construction. If the schema was never bootstrapped, the first storage access
fails.

**Fix:** Call `SchemaBootstrapper::ensureSchema()` once during install, deploy,
or boot.

```php
use Ucp\Sdk\Symfony\Bridge\DoctrineDbal\SchemaBootstrapper;

$container->get(SchemaBootstrapper::class)->ensureSchema();
```

See the [bootstrap example kernel](../examples/bootstrap-symfony-app/src/Kernel.php).

## Signature verification fails or requests are rejected as unsigned

**Cause:** `signature_policy` is `strict` but there is no active signing key, the
request signature has expired, or the clocks are skewed.

**Fix:**
- Generate a signing key before enabling strict mode or outbound webhooks:
  `bin/console ucp:signing-keys:generate`.
- Inspect published public keys with `bin/console ucp:signing-keys:show-public`.
- Start with `signature_policy: log` locally to observe verification results
  without rejecting traffic, then move to `strict` for production.
- Signatures carry `created`/`expires` and a maximum lifetime window; ensure the
  server clock is correct and the signature lifetime is within the allowed
  window.

## "Request signature replay detected."

**Cause:** The same signature was presented twice. The replay guard records each
verified signature nonce and rejects repeats.

**Fix:** This is expected protection — each signed request must be unique. If you
see it for legitimate distinct requests, confirm you are not retrying with the
exact same signed headers; sign each attempt freshly. Expired nonces are purged
by `bin/console ucp:storage:cleanup-signature-nonces`.

## A2A or embedded endpoints return 404

**Cause:** `transports` defaults to `rest`. The `a2a` and `embedded` routes
return not found until the transport is explicitly enabled.

**Fix:** Add the transport to `transports` in the bundle configuration, e.g.
`transports: ['rest', 'a2a']`. Embedded responses additionally only allow
configured agent origins.

## MCP endpoint does not respond

**Cause:** MCP is metadata-only in the shared SDK. The SDK advertises MCP profile
metadata but does not generate or handle a runtime `/ucp/mcp` endpoint.

**Fix:** Provide the endpoint yourself via `transport_endpoints.mcp`. A working
MCP runtime is the adopter's responsibility (in Shopware, it depends on the
6.7 core Store API MCP endpoint). See [full-ucp-parity-plan.md](full-ucp-parity-plan.md).

## Remote profile fetch is blocked

**Cause:** The host of the profile being fetched is not in
`allowed_profile_hosts`, or a non-public/unsafe URL was rejected by the URL
safety checks (which block loopback, link-local, and metadata addresses).

**Fix:** Add the legitimate host to `allowed_profile_hosts`. For local
development against a non-public host, the example apps enable a development
mode for profile fetching.

## Idempotent request returns a stale or unexpected response

**Cause:** Idempotency keys deduplicate requests. Reusing a key returns the
stored response for the original request rather than processing a new one.

**Fix:** Use a fresh idempotency key per distinct operation. Stored idempotent
bodies are capped (default `262144` bytes); oversized responses are not stored.
Expired idempotency state is purged by `bin/console ucp:storage:cleanup`.

## OAuth authorization code is rejected

**Cause:** Authorization codes are single-use and short-lived (default `600`
seconds). Reusing a code or exchanging it after expiry fails.

**Fix:** Exchange each code once, promptly. Re-initiate the flow if the code has
expired.

## Request body rejected as too large

**Cause:** Request bodies are capped (default `262144` bytes).

**Fix:** Keep payloads within the configured limit, or raise the cap in
configuration if your integration legitimately needs larger bodies.
