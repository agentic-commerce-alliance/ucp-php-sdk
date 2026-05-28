<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Bridge\DoctrineDbal;

use Doctrine\DBAL\Connection;

final class SchemaBootstrapper
{
    private bool $initialized = false;

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function ensureSchema(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS ucp_signing_keys (kid TEXT PRIMARY KEY, public_key_pem TEXT NOT NULL, private_key_pem TEXT NOT NULL, algorithm TEXT NOT NULL, key_type TEXT NOT NULL DEFAULT \'EC\', key_use TEXT NOT NULL DEFAULT \'sig\', status TEXT NOT NULL DEFAULT \'active\', curve TEXT DEFAULT NULL, created_at TEXT DEFAULT NULL, retire_at TEXT DEFAULT NULL)');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS ucp_idempotency (idempotency_key TEXT PRIMARY KEY, fingerprint TEXT NOT NULL, status TEXT NOT NULL, response_body TEXT DEFAULT NULL, status_code INTEGER DEFAULT NULL, replayable INTEGER NOT NULL DEFAULT 1, expires_at INTEGER DEFAULT NULL)');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS ucp_oauth_state (code_hash TEXT PRIMARY KEY, client_id TEXT NOT NULL, subject TEXT NOT NULL, refresh_token TEXT DEFAULT NULL, expires_at INTEGER NOT NULL, consumed_at INTEGER DEFAULT NULL, created_at INTEGER NOT NULL)');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS ucp_platform_profile_cache (uri TEXT PRIMARY KEY, payload TEXT NOT NULL, expires_at INTEGER DEFAULT NULL)');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS ucp_negotiation_sessions (id TEXT PRIMARY KEY, platform_profile_uri TEXT NOT NULL, protocol_version TEXT NOT NULL, active_capabilities TEXT NOT NULL, payment_handler_ids TEXT DEFAULT NULL, tenant_identifier TEXT DEFAULT NULL, last_used_at TEXT DEFAULT NULL, expires_at INTEGER DEFAULT NULL)');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS ucp_signature_nonces (scope TEXT NOT NULL, kid TEXT NOT NULL, signature_hash TEXT NOT NULL, created_at INTEGER DEFAULT NULL, PRIMARY KEY(scope, kid, signature_hash))');
        $this->ensureColumn('ucp_idempotency', 'replayable', 'INTEGER NOT NULL DEFAULT 1');
        $this->ensureColumn('ucp_idempotency', 'expires_at', 'INTEGER DEFAULT NULL');
        $this->ensureColumn('ucp_oauth_state', 'code_hash', 'TEXT DEFAULT NULL');
        $this->ensureColumn('ucp_oauth_state', 'expires_at', 'INTEGER DEFAULT NULL');
        $this->ensureColumn('ucp_oauth_state', 'consumed_at', 'INTEGER DEFAULT NULL');
        $this->ensureColumn('ucp_oauth_state', 'created_at', 'INTEGER DEFAULT NULL');
        $this->ensureColumn('ucp_negotiation_sessions', 'expires_at', 'INTEGER DEFAULT NULL');
        $this->connection->executeStatement('CREATE UNIQUE INDEX IF NOT EXISTS idx_ucp_oauth_state_code_hash ON ucp_oauth_state (code_hash)');
        $this->initialized = true;
    }

    private function ensureColumn(string $table, string $column, string $definition): void
    {
        $columns = array_change_key_case($this->connection->createSchemaManager()->listTableColumns($table), CASE_LOWER);
        if (isset($columns[strtolower($column)])) {
            return;
        }

        $this->connection->executeStatement(sprintf('ALTER TABLE %s ADD COLUMN %s %s', $table, $column, $definition));
    }
}
