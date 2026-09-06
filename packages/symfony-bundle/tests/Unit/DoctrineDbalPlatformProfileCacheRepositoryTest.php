<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Unit;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Model\Profile\CachedPlatformProfile;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Symfony\Bridge\DoctrineDbal\DoctrineDbalPlatformProfileCacheRepository;
use Ucp\Sdk\Symfony\Bridge\DoctrineDbal\SchemaBootstrapper;

final class DoctrineDbalPlatformProfileCacheRepositoryTest extends TestCase
{
    #[Test]
    public function itStoresFreshProfilesAndCanOptionallyReadExpiredEntries(): void
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        (new SchemaBootstrapper($connection))->ensureSchema();
        $repository = new DoctrineDbalPlatformProfileCacheRepository(
            $connection,
            600,
        );
        $profile = new PlatformProfile('2026-04-08', [], [], [], [], [
            '2026-04-08' => 'https://merchant.example/.well-known/ucp',
        ]);

        $repository->save('https://platform.example/.well-known/ucp', $profile);
        self::assertNotNull($repository->find('https://platform.example/.well-known/ucp'));

        $connection->executeStatement(
            'UPDATE ucp_platform_profile_cache SET expires_at = :expires_at WHERE uri = :uri',
            [
                'expires_at' => time() - 10,
                'uri' => 'https://platform.example/.well-known/ucp',
            ],
        );

        self::assertNull($repository->find('https://platform.example/.well-known/ucp'));
        self::assertNotNull($repository->find('https://platform.example/.well-known/ucp', true));
    }

    #[Test]
    public function itListsAndDeletesCachedProfiles(): void
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        (new SchemaBootstrapper($connection))->ensureSchema();
        $repository = new DoctrineDbalPlatformProfileCacheRepository(
            $connection,
            600,
        );
        $profile = new PlatformProfile('2026-04-08', [], [], [], [], [
            '2026-04-08' => 'https://merchant.example/.well-known/ucp',
        ]);

        $repository->save('https://a.example/.well-known/ucp', $profile);
        $repository->save('https://b.example/.well-known/ucp', $profile);

        self::assertSame([
            'https://a.example/.well-known/ucp',
            'https://b.example/.well-known/ucp',
        ], array_keys($repository->all()));

        self::assertTrue($repository->delete('https://a.example/.well-known/ucp'));
        self::assertFalse($repository->delete('https://a.example/.well-known/ucp'));
        self::assertSame(['https://b.example/.well-known/ucp'], array_keys($repository->all()));
    }

    #[Test]
    public function itStoresTheEntrysFreshnessAndValidatorAndKeepsTheFixedTtlForPlainSaves(): void
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        (new SchemaBootstrapper($connection))->ensureSchema();
        $repository = new DoctrineDbalPlatformProfileCacheRepository($connection, 600);
        $profile = new PlatformProfile('2026-04-08', [], [], [], [], [
            '2026-04-08' => 'https://merchant.example/.well-known/ucp',
        ]);

        $repository->saveEntry('https://platform.example/.well-known/ucp', $profile, 1_900_000_000, '"v1"');
        $entry = $repository->findEntry('https://platform.example/.well-known/ucp');

        self::assertInstanceOf(CachedPlatformProfile::class, $entry);
        self::assertSame(1_900_000_000, $entry->expiresAt);
        self::assertSame('"v1"', $entry->etag);
        self::assertTrue($entry->isFresh(1_899_999_999));
        self::assertFalse($entry->isFresh(1_900_000_001));
        self::assertEquals($profile, $entry->profile);

        // An entry saved through the base interface has no validator and the repository's TTL.
        $before = time();
        $repository->save('https://platform.example/.well-known/ucp', $profile);
        $plain = $repository->findEntry('https://platform.example/.well-known/ucp');
        self::assertInstanceOf(CachedPlatformProfile::class, $plain);
        self::assertNull($plain->etag);
        self::assertGreaterThanOrEqual($before + 600, $plain->expiresAt);

        self::assertNull($repository->findEntry('https://nobody.example/.well-known/ucp'));
    }
}
