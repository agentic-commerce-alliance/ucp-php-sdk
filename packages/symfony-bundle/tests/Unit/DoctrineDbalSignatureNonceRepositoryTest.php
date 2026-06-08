<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Unit;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Symfony\Bridge\DoctrineDbal\DoctrineDbalSignatureNonceRepository;
use Ucp\Sdk\Symfony\Bridge\DoctrineDbal\SchemaBootstrapper;

final class DoctrineDbalSignatureNonceRepositoryTest extends TestCase
{
    #[Test]
    public function itDoesNotBootstrapSchemaFromTheRepositoryConstructor(): void
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $repository = new DoctrineDbalSignatureNonceRepository($connection);

        self::assertInstanceOf(DoctrineDbalSignatureNonceRepository::class, $repository);
        self::assertNotContains('ucp_signature_nonces', array_map('strtolower', $connection->createSchemaManager()->listTableNames()));
    }

    #[Test]
    public function itPurgesExpiredSignatureNonces(): void
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        (new SchemaBootstrapper($connection))->ensureSchema();
        $repository = new DoctrineDbalSignatureNonceRepository($connection);

        $repository->save('scope-a', 'kid-1', 'hash-old', 10);
        $repository->save('scope-a', 'kid-1', 'hash-new', 200);
        $repository->purgeExpired(100);

        self::assertFalse($repository->has('scope-a', 'kid-1', 'hash-old'));
        self::assertTrue($repository->has('scope-a', 'kid-1', 'hash-new'));
    }

    #[Test]
    public function itRejectsDuplicateSignatureNoncesAtomically(): void
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        (new SchemaBootstrapper($connection))->ensureSchema();
        $repository = new DoctrineDbalSignatureNonceRepository($connection);

        self::assertTrue($repository->saveIfNew('scope-a', 'kid-1', 'hash-1', 10));
        self::assertFalse($repository->saveIfNew('scope-a', 'kid-1', 'hash-1', 20));
    }
}
