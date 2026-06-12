<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Unit;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Symfony\Bridge\DoctrineDbal\SchemaBootstrapper;

final class SchemaBootstrapperTest extends TestCase
{
    #[Test]
    public function itCanRunMultipleTimesOnTheSameInstance(): void
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $bootstrapper = new SchemaBootstrapper($connection);

        $bootstrapper->ensureSchema();
        $connection->executeStatement('DROP TABLE ucp_signature_nonces');
        $bootstrapper->ensureSchema();

        self::assertContains('ucp_signature_nonces', $this->tableNames($connection));
    }

    #[Test]
    public function itMigratesExistingStorageTablesToTheDesiredSchema(): void
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $connection->executeStatement('CREATE TABLE application_table (id INTEGER PRIMARY KEY)');
        $connection->executeStatement('CREATE TABLE ucp_signing_keys (kid TEXT PRIMARY KEY, public_key_pem TEXT NOT NULL, private_key_pem TEXT NOT NULL, algorithm TEXT NOT NULL)');
        $connection->executeStatement('CREATE TABLE ucp_idempotency (idempotency_key TEXT PRIMARY KEY, fingerprint TEXT NOT NULL, status TEXT NOT NULL, response_body TEXT DEFAULT NULL, status_code INTEGER DEFAULT NULL)');
        $connection->insert('ucp_signing_keys', [
            'kid' => 'kid-1',
            'public_key_pem' => 'public',
            'private_key_pem' => 'private',
            'algorithm' => 'ES256',
        ]);
        $connection->insert('ucp_idempotency', [
            'idempotency_key' => 'idem-1',
            'fingerprint' => 'fingerprint-1',
            'status' => 'started',
            'response_body' => null,
            'status_code' => null,
        ]);

        $bootstrapper = new SchemaBootstrapper($connection);
        $bootstrapper->ensureSchema();
        $bootstrapper->ensureSchema();

        $signingKey = $connection->fetchAssociative('SELECT * FROM ucp_signing_keys WHERE kid = :kid', ['kid' => 'kid-1']);
        self::assertIsArray($signingKey);
        self::assertSame('', $signingKey['tenant_identifier']);
        self::assertSame('EC', $signingKey['key_type']);
        self::assertSame('sig', $signingKey['key_use']);
        self::assertSame('active', $signingKey['status']);

        $idempotency = $connection->fetchAssociative('SELECT * FROM ucp_idempotency WHERE idempotency_key = :key', ['key' => 'idem-1']);
        self::assertIsArray($idempotency);
        self::assertSame('1', (string) $idempotency['replayable']);
        self::assertNull($idempotency['expires_at']);

        $schemaManager = $connection->createSchemaManager();
        $primaryKey = $schemaManager->listTableIndexes('ucp_signing_keys')['primary'] ?? null;
        self::assertNotNull($primaryKey);
        self::assertSame(['tenant_identifier', 'kid'], $primaryKey->getColumns());
        $oauthIndexes = array_change_key_case($schemaManager->listTableIndexes('ucp_oauth_state'), CASE_LOWER);
        self::assertArrayNotHasKey('idx_ucp_oauth_state_code_hash', $oauthIndexes);
        self::assertContains('application_table', $this->tableNames($connection));
    }

    #[Test]
    public function itIgnoresForeignTablesWithColumnTypesThePlatformCannotMap(): void
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        // Foreign table with a column type the platform cannot reflect (e.g. SwagCommercial's
        // `b2b_employee.status` ENUM). The bootstrapper must skip it, not throw.
        $connection->executeStatement('CREATE TABLE foreign_plugin_table (id INTEGER PRIMARY KEY, status enum NOT NULL)');

        $bootstrapper = new SchemaBootstrapper($connection);
        $bootstrapper->ensureSchema();

        self::assertContains('ucp_signing_keys', $this->tableNames($connection));
        self::assertContains('foreign_plugin_table', $this->tableNames($connection));
    }

    /**
     * @return list<string>
     */
    private function tableNames(\Doctrine\DBAL\Connection $connection): array
    {
        return array_map('strtolower', $connection->createSchemaManager()->listTableNames());
    }
}
