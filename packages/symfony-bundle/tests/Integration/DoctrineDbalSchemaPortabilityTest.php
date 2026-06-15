<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Integration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Symfony\Bridge\DoctrineDbal\ConnectionFactory;
use Ucp\Sdk\Symfony\Bridge\DoctrineDbal\SchemaBootstrapper;

final class DoctrineDbalSchemaPortabilityTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function portableStorageDsnProvider(): iterable
    {
        yield 'mysql' => ['UCP_SDK_TEST_MYSQL_DSN', 'ucp_signing_keys'];
        yield 'postgres' => ['UCP_SDK_TEST_POSTGRES_DSN', 'ucp_signing_keys'];
    }

    #[Test]
    #[DataProvider('portableStorageDsnProvider')]
    public function itBootstrapsTheDefaultStorageSchemaOnAdvertisedDrivers(string $dsnEnvironmentVariable, string $expectedTable): void
    {
        $dsn = getenv($dsnEnvironmentVariable);
        if (! is_string($dsn) || $dsn === '') {
            self::markTestSkipped($dsnEnvironmentVariable . ' is not configured.');
        }

        $connection = ConnectionFactory::create($dsn);
        $this->resetSdkTables($connection);

        $bootstrapper = new SchemaBootstrapper($connection);
        $bootstrapper->ensureSchema();
        $bootstrapper->ensureSchema();

        self::assertContains($expectedTable, array_map('strtolower', $connection->createSchemaManager()->listTableNames()));
    }

    private function resetSdkTables(Connection $connection): void
    {
        foreach ($connection->createSchemaManager()->listTableNames() as $tableName) {
            if (str_starts_with(strtolower($tableName), 'ucp_')) {
                $connection->executeStatement('DROP TABLE ' . $connection->quoteIdentifier($tableName));
            }
        }
    }
}
