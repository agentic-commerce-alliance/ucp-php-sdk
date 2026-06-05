# Storage Adapters

The Symfony bundle ships a default storage adapter based on Doctrine DBAL.

## What It Stores

- managed signing keys
- idempotency records
- OAuth state
- platform-profile cache
- negotiation sessions
- signature replay nonces

Sensitive default-storage records are protected:

- managed private keys are encrypted at rest
- OAuth refresh tokens are encrypted at rest
- stored idempotent response bodies are encrypted at rest

## What It Does Not Store

The default storage adapter is not the commerce-platform model.

It should not own:

- product data
- cart state
- order state
- customer state
- sales-channel configuration

Those concerns belong to the host platform or plugin.

## Replacement Pattern

Replace repository interfaces, not bundle controllers.

Examples:

- `ManagedSigningKeyRepositoryInterface`
- `PlatformProfileCacheRepositoryInterface`
- `NegotiationSessionRepositoryInterface`
- `SignatureNonceRepositoryInterface`

The merchant example app shows this boundary by keeping SDK state in SQLite while merchant state stays in JSON files.

## Retention And Cleanup

The default storage adapter keeps TTL-based records for:

- OAuth authorization codes
- idempotency rows
- negotiation sessions
- platform-profile cache rows
- signature replay nonces

Use `ucp:storage:cleanup` to purge expired rows and old retired signing keys in one run.

For Shopware plugins, prefer plugin-owned tables or DAL-backed replacements
where SDK state needs to live with other Shopware-managed data. Keep
sales-channel UCP configuration in the plugin, not in the shared SDK storage
adapter.
