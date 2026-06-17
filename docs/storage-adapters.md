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

Encrypted values in the default DBAL storage adapter fail closed. If an encrypted
refresh token, managed private key, or idempotent response body cannot be decoded
with the current application secret and encryption context, the repository raises
the decrypt error instead of treating the stored value as plaintext.

The default encryptor derives its key from `%kernel.secret%`. Changing
`%kernel.secret%` without re-encrypting existing SDK storage rows makes those
encrypted rows unreadable. Rotate that secret only with an explicit migration
plan, or purge short-lived SDK state such as OAuth state and idempotency rows
where losing replay or cache state is acceptable.

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

## Schema Lifecycle

The default DBAL repositories expect their tables to exist before they are used.
They do not install or migrate the schema from request-time repository
constructors.

If you use the default DBAL storage adapter, run the schema bootstrapper during
your app install, update, deployment, or startup lifecycle:

```php
use Ucp\Sdk\Symfony\Bridge\DoctrineDbal\SchemaBootstrapper;

final readonly class InstallUcpStorage
{
    public function __construct(private SchemaBootstrapper $bootstrapper)
    {
    }

    public function __invoke(): void
    {
        $this->bootstrapper->ensureSchema();
    }
}
```

`ensureSchema()` compares the current SDK-owned DBAL schema with the desired SDK
schema and applies the necessary changes. Tables outside the SDK schema are left
untouched.

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
