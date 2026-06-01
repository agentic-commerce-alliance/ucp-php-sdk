<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Unit;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Internal\Security\DefaultSigningKeyManager;
use Ucp\Sdk\Symfony\Bridge\DefaultStorage\DefaultPrivateKeyEncryptor;
use Ucp\Sdk\Symfony\Bridge\DoctrineDbal\DoctrineDbalSigningKeyRepository;
use Ucp\Sdk\Symfony\Bridge\DoctrineDbal\SchemaBootstrapper;

final class DoctrineDbalSigningKeyRepositoryTest extends TestCase
{
    #[Test]
    public function itStoresAndLoadsManagedSigningKeys(): void
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $repository = new DoctrineDbalSigningKeyRepository(
            $connection,
            new SchemaBootstrapper($connection),
            new DefaultPrivateKeyEncryptor('test-secret'),
        );
        $manager = new DefaultSigningKeyManager();
        $key = $manager->generate('kid-1');

        $repository->saveManaged($key);
        $loaded = $repository->findManaged('kid-1');

        self::assertNotNull($loaded);
        self::assertSame($key->kid, $loaded->kid);
        self::assertSame($key->privateKeyPem, $loaded->privateKeyPem);
        self::assertCount(1, $repository->allManaged());
        self::assertCount(1, $repository->active());
    }

    #[Test]
    public function itDeletesManagedSigningKeys(): void
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $repository = new DoctrineDbalSigningKeyRepository(
            $connection,
            new SchemaBootstrapper($connection),
            new DefaultPrivateKeyEncryptor('test-secret'),
        );
        $manager = new DefaultSigningKeyManager();
        $key = $manager->generate('kid-delete');

        $repository->saveManaged($key);

        self::assertTrue($repository->deleteManaged('kid-delete'));
        self::assertNull($repository->findManaged('kid-delete'));
        self::assertFalse($repository->deleteManaged('kid-delete'));
    }

    #[Test]
    public function itScopesManagedSigningKeysByTenant(): void
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $repository = new DoctrineDbalSigningKeyRepository(
            $connection,
            new SchemaBootstrapper($connection),
            new DefaultPrivateKeyEncryptor('test-secret'),
        );
        $manager = new DefaultSigningKeyManager();

        $repository->saveManagedForTenant('sales-channel-a', $manager->generate('shared-kid'));
        $repository->saveManagedForTenant('sales-channel-b', $manager->generate('shared-kid'));

        self::assertNotNull($repository->findManagedForTenant('sales-channel-a', 'shared-kid'));
        self::assertNotNull($repository->findManagedForTenant('sales-channel-b', 'shared-kid'));
        self::assertCount(1, $repository->allManagedForTenant('sales-channel-a'));
        self::assertTrue($repository->deleteManagedForTenant('sales-channel-a', 'shared-kid'));
        self::assertNull($repository->findManagedForTenant('sales-channel-a', 'shared-kid'));
        self::assertNotNull($repository->findManagedForTenant('sales-channel-b', 'shared-kid'));
    }

    #[Test]
    public function itPurgesRetiredKeysWithoutCrossTenantDeletes(): void
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $repository = new DoctrineDbalSigningKeyRepository(
            $connection,
            new SchemaBootstrapper($connection),
            new DefaultPrivateKeyEncryptor('test-secret'),
        );

        $repository->saveManagedForTenant('sales-channel-a', new \Ucp\Sdk\Model\Security\ManagedSigningKey(
            'shared-kid',
            'public-a',
            'private-a',
            status: 'retired',
            retireAt: '2026-01-01T00:00:00+00:00',
        ));
        $repository->saveManagedForTenant('sales-channel-b', new \Ucp\Sdk\Model\Security\ManagedSigningKey(
            'shared-kid',
            'public-b',
            'private-b',
            status: 'active',
        ));

        $repository->purgeRetired('2026-02-01T00:00:00+00:00');

        self::assertNull($repository->findManagedForTenant('sales-channel-a', 'shared-kid'));
        self::assertNotNull($repository->findManagedForTenant('sales-channel-b', 'shared-kid'));
    }
}
