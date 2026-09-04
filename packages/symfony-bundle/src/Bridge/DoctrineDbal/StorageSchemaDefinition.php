<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Bridge\DoctrineDbal;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaConfig;

/** @internal */
final class StorageSchemaDefinition
{
    /**
     * @param list<string> $namespaces
     */
    public function createSchema(SchemaConfig $schemaConfig, array $namespaces): Schema
    {
        // Keep the complete SDK storage contract in this class so migrations have one schema source of truth.
        $schema = new Schema([], [], $schemaConfig, $namespaces);

        $table = $schema->createTable('ucp_signing_keys');
        $table->addColumn('tenant_identifier', 'string', ['length' => 191, 'default' => '']);
        $table->addColumn('kid', 'string', ['length' => 191]);
        $table->addColumn('public_key_pem', 'text');
        $table->addColumn('private_key_pem', 'text');
        $table->addColumn('algorithm', 'string', ['length' => 255]);
        $table->addColumn('key_type', 'string', ['length' => 255, 'default' => 'EC']);
        $table->addColumn('key_use', 'string', ['length' => 255, 'default' => 'sig']);
        $table->addColumn('status', 'string', ['length' => 255, 'default' => 'active']);
        $table->addColumn('curve', 'string', ['length' => 255, 'notnull' => false]);
        $table->addColumn('created_at', 'string', ['length' => 255, 'notnull' => false]);
        $table->addColumn('retire_at', 'string', ['length' => 255, 'notnull' => false]);
        $table->setPrimaryKey(['tenant_identifier', 'kid']);

        $table = $schema->createTable('ucp_idempotency');
        $table->addColumn('idempotency_key', 'string', ['length' => 191]);
        $table->addColumn('fingerprint', 'string', ['length' => 255]);
        $table->addColumn('status', 'string', ['length' => 255]);
        $table->addColumn('response_body', 'text', ['notnull' => false]);
        $table->addColumn('status_code', 'integer', ['notnull' => false]);
        $table->addColumn('replayable', 'integer', ['default' => 1]);
        $table->addColumn('expires_at', 'integer', ['notnull' => false]);
        $table->setPrimaryKey(['idempotency_key']);
        $table->addIndex(['expires_at'], 'idx_ucp_idempotency_expires_at');

        $table = $schema->createTable('ucp_oauth_state');
        $table->addColumn('code_hash', 'string', ['length' => 191]);
        $table->addColumn('client_id', 'string', ['length' => 500]);
        $table->addColumn('subject', 'string', ['length' => 500]);
        $table->addColumn('refresh_token', 'text', ['notnull' => false]);
        $table->addColumn('expires_at', 'integer');
        $table->addColumn('consumed_at', 'integer', ['notnull' => false]);
        $table->addColumn('created_at', 'integer');
        $table->setPrimaryKey(['code_hash']);

        $table = $schema->createTable('ucp_platform_profile_cache');
        $table->addColumn('uri', 'string', ['length' => 500]);
        $table->addColumn('payload', 'text');
        $table->addColumn('expires_at', 'integer', ['notnull' => false]);
        $table->setPrimaryKey(['uri']);

        $table = $schema->createTable('ucp_agent_key_directory_cache');
        $table->addColumn('uri', 'string', ['length' => 500]);
        $table->addColumn('payload', 'text');
        $table->addColumn('expires_at', 'integer', ['notnull' => false]);
        $table->setPrimaryKey(['uri']);

        $table = $schema->createTable('ucp_negotiation_sessions');
        $table->addColumn('id', 'string', ['length' => 191]);
        $table->addColumn('platform_profile_uri', 'string', ['length' => 500]);
        $table->addColumn('protocol_version', 'string', ['length' => 255]);
        $table->addColumn('active_capabilities', 'text');
        $table->addColumn('payment_handler_ids', 'text', ['notnull' => false]);
        $table->addColumn('tenant_identifier', 'string', ['length' => 191, 'notnull' => false]);
        $table->addColumn('last_used_at', 'string', ['length' => 255, 'notnull' => false]);
        $table->addColumn('expires_at', 'integer', ['notnull' => false]);
        $table->setPrimaryKey(['id']);

        $table = $schema->createTable('ucp_signature_nonces');
        $table->addColumn('scope', 'string', ['length' => 191]);
        $table->addColumn('kid', 'string', ['length' => 191]);
        $table->addColumn('signature_hash', 'string', ['length' => 191]);
        $table->addColumn('created_at', 'integer', ['notnull' => false]);
        $table->setPrimaryKey(['scope', 'kid', 'signature_hash']);

        return $schema;
    }

    /**
     * Names of the tables owned by this definition, unqualified and lower-cased to match
     * the form schema introspection returns.
     *
     * Read straight from {@see createSchema()} so the list always reflects the real table
     * definitions. The bootstrapper uses it to introspect only SDK tables and leave every
     * other table — along with whatever column types it uses — out of reflection.
     *
     * @return list<string>
     */
    public function tableNames(): array
    {
        $names = [];
        foreach ($this->createSchema(new SchemaConfig(), [])->getTables() as $table) {
            $name = $table->getName();
            $separatorPosition = strrpos($name, '.');
            if ($separatorPosition !== false) {
                $name = substr($name, $separatorPosition + 1);
            }

            $names[] = strtolower($name);
        }

        return $names;
    }
}
