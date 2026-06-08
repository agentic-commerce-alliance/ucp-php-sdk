<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Bridge\DoctrineDbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;

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

        $this->createTables();
        $this->ensureColumn('ucp_idempotency', 'replayable', $this->integerType().' NOT NULL DEFAULT 1');
        $this->ensureColumn('ucp_idempotency', 'expires_at', $this->integerType().' DEFAULT NULL');
        $this->ensureColumn('ucp_oauth_state', 'code_hash', $this->keyType().' DEFAULT NULL');
        $this->ensureColumn('ucp_oauth_state', 'expires_at', $this->integerType().' DEFAULT NULL');
        $this->ensureColumn('ucp_oauth_state', 'consumed_at', $this->integerType().' DEFAULT NULL');
        $this->ensureColumn('ucp_oauth_state', 'created_at', $this->integerType().' DEFAULT NULL');
        $this->ensureColumn('ucp_signing_keys', 'tenant_identifier', $this->keyType().' NOT NULL DEFAULT \'\'');
        $this->migrateSigningKeysTenantPrimaryKeyForSqlite();
        $this->ensureColumn('ucp_negotiation_sessions', 'expires_at', $this->integerType().' DEFAULT NULL');
        $this->initialized = true;
    }

    private function createTables(): void
    {
        $keyType = $this->keyType();
        $longTextType = $this->longTextType();
        $integerType = $this->integerType();
        $shortTextType = $this->shortTextType();
        $uriType = $this->uriType();

        $this->connection->executeStatement(\sprintf('CREATE TABLE IF NOT EXISTS ucp_signing_keys (tenant_identifier %s NOT NULL DEFAULT \'\', kid %s NOT NULL, public_key_pem %s NOT NULL, private_key_pem %s NOT NULL, algorithm %s NOT NULL, key_type %s NOT NULL DEFAULT \'EC\', key_use %s NOT NULL DEFAULT \'sig\', status %s NOT NULL DEFAULT \'active\', curve %s DEFAULT NULL, created_at %s DEFAULT NULL, retire_at %s DEFAULT NULL, PRIMARY KEY(tenant_identifier, kid))', $keyType, $keyType, $longTextType, $longTextType, $shortTextType, $shortTextType, $shortTextType, $shortTextType, $shortTextType, $shortTextType, $shortTextType));
        $this->connection->executeStatement(\sprintf('CREATE TABLE IF NOT EXISTS ucp_idempotency (idempotency_key %s PRIMARY KEY, fingerprint %s NOT NULL, status %s NOT NULL, response_body %s DEFAULT NULL, status_code %s DEFAULT NULL, replayable %s NOT NULL DEFAULT 1, expires_at %s DEFAULT NULL)', $keyType, $shortTextType, $shortTextType, $longTextType, $integerType, $integerType, $integerType));
        $this->connection->executeStatement(\sprintf('CREATE TABLE IF NOT EXISTS ucp_oauth_state (code_hash %s PRIMARY KEY, client_id %s NOT NULL, subject %s NOT NULL, refresh_token %s DEFAULT NULL, expires_at %s NOT NULL, consumed_at %s DEFAULT NULL, created_at %s NOT NULL)', $keyType, $uriType, $uriType, $longTextType, $integerType, $integerType, $integerType));
        $this->connection->executeStatement(\sprintf('CREATE TABLE IF NOT EXISTS ucp_platform_profile_cache (uri %s PRIMARY KEY, payload %s NOT NULL, expires_at %s DEFAULT NULL)', $uriType, $longTextType, $integerType));
        $this->connection->executeStatement(\sprintf('CREATE TABLE IF NOT EXISTS ucp_negotiation_sessions (id %s PRIMARY KEY, platform_profile_uri %s NOT NULL, protocol_version %s NOT NULL, active_capabilities %s NOT NULL, payment_handler_ids %s DEFAULT NULL, tenant_identifier %s DEFAULT NULL, last_used_at %s DEFAULT NULL, expires_at %s DEFAULT NULL)', $keyType, $uriType, $shortTextType, $longTextType, $longTextType, $keyType, $shortTextType, $integerType));
        $this->connection->executeStatement(\sprintf('CREATE TABLE IF NOT EXISTS ucp_signature_nonces (scope %s NOT NULL, kid %s NOT NULL, signature_hash %s NOT NULL, created_at %s DEFAULT NULL, PRIMARY KEY(scope, kid, signature_hash))', $keyType, $keyType, $keyType, $integerType));
    }

    private function ensureColumn(string $table, string $column, string $definition): void
    {
        $columns = array_change_key_case($this->connection->createSchemaManager()->listTableColumns($table), CASE_LOWER);
        if (isset($columns[strtolower($column)])) {
            return;
        }

        $this->connection->executeStatement(sprintf('ALTER TABLE %s ADD COLUMN %s %s', $table, $column, $definition));
    }

    private function migrateSigningKeysTenantPrimaryKeyForSqlite(): void
    {
        if (!$this->isSqlite()) {
            return;
        }

        try {
            $columns = $this->connection->fetchAllAssociative('PRAGMA table_info(ucp_signing_keys)');
        } catch (\Throwable) {
            return;
        }

        $primaryKeyColumns = [];
        foreach ($columns as $column) {
            if ((int) ($column['pk'] ?? 0) > 0) {
                $primaryKeyColumns[(int) $column['pk']] = (string) $column['name'];
            }
        }

        ksort($primaryKeyColumns);
        if (array_values($primaryKeyColumns) !== ['kid']) {
            return;
        }

        $this->connection->executeStatement('DROP TABLE IF EXISTS ucp_signing_keys_migrated');
        $this->connection->executeStatement('CREATE TABLE ucp_signing_keys_migrated (tenant_identifier TEXT NOT NULL DEFAULT \'\', kid TEXT NOT NULL, public_key_pem TEXT NOT NULL, private_key_pem TEXT NOT NULL, algorithm TEXT NOT NULL, key_type TEXT NOT NULL DEFAULT \'EC\', key_use TEXT NOT NULL DEFAULT \'sig\', status TEXT NOT NULL DEFAULT \'active\', curve TEXT DEFAULT NULL, created_at TEXT DEFAULT NULL, retire_at TEXT DEFAULT NULL, PRIMARY KEY(tenant_identifier, kid))');
        $this->connection->executeStatement('INSERT INTO ucp_signing_keys_migrated (tenant_identifier, kid, public_key_pem, private_key_pem, algorithm, key_type, key_use, status, curve, created_at, retire_at) SELECT COALESCE(tenant_identifier, \'\'), kid, public_key_pem, private_key_pem, algorithm, COALESCE(key_type, \'EC\'), COALESCE(key_use, \'sig\'), COALESCE(status, \'active\'), curve, created_at, retire_at FROM ucp_signing_keys');
        $this->connection->executeStatement('DROP TABLE ucp_signing_keys');
        $this->connection->executeStatement('ALTER TABLE ucp_signing_keys_migrated RENAME TO ucp_signing_keys');
    }

    private function keyType(): string
    {
        return $this->isMySQL() ? 'VARCHAR(191)' : 'TEXT';
    }

    private function shortTextType(): string
    {
        return $this->isMySQL() ? 'VARCHAR(255)' : 'TEXT';
    }

    private function uriType(): string
    {
        return $this->isMySQL() ? 'VARCHAR(500)' : 'TEXT';
    }

    private function longTextType(): string
    {
        return $this->isMySQL() ? 'LONGTEXT' : 'TEXT';
    }

    private function integerType(): string
    {
        return $this->isMySQL() ? 'INT' : 'INTEGER';
    }

    private function isMySQL(): bool
    {
        return $this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
    }

    private function isSqlite(): bool
    {
        return str_contains(strtolower($this->connection->getDatabasePlatform()::class), 'sqlite');
    }
}
