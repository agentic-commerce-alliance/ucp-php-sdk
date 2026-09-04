<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Unit;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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
    public function findReturnsNullForAUriThatWasNeverCached(): void
    {
        $repository = new DoctrineDbalPlatformProfileCacheRepository($this->bootstrappedConnection(), 600);

        self::assertNull($repository->find('https://never-seen.example/.well-known/ucp'));
    }

    /**
     * Re-fetching a profile must refresh the cached copy in place. The early return after
     * a successful UPDATE was never taken, and the branch it skips is an INSERT that would
     * violate the unique key on `uri` -- so without it, the second fetch of any profile
     * would raise rather than update.
     */
    #[Test]
    public function reSavingAProfileRefreshesTheCachedCopyInPlace(): void
    {
        $connection = $this->bootstrappedConnection();
        $repository = new DoctrineDbalPlatformProfileCacheRepository($connection, 600);
        $uri = 'https://platform.example/.well-known/ucp';

        $repository->save($uri, new PlatformProfile('2026-04-08', [], [], [], [], []));
        $repository->save($uri, new PlatformProfile('2026-08-25', [], [], [], [], []));

        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM ucp_platform_profile_cache'));
        self::assertSame('2026-08-25', $repository->find($uri)?->version);
    }

    /**
     * Only reachable when another request caches the same URI between this one's UPDATE
     * and its INSERT, which is what two agents discovering the same platform at once do.
     * The connection is stubbed to produce that interleaving because no single-threaded
     * test can.
     */
    #[Test]
    public function aProfileCachedConcurrentlyByAnotherRequestIsUpdatedRatherThanRaising(): void
    {
        $connection = $this->createMock(Connection::class);
        $updates = [];
        $connection
            ->method('update')
            ->willReturnCallback(static function (string $table, array $data, array $criteria) use (&$updates): int {
                $updates[] = $criteria;

                return count($updates) === 1 ? 0 : 1;
            });
        $connection
            ->method('insert')
            ->willThrowException((new \ReflectionClass(UniqueConstraintViolationException::class))->newInstanceWithoutConstructor());

        $repository = new DoctrineDbalPlatformProfileCacheRepository($connection, 600);

        $repository->save('https://platform.example/.well-known/ucp', new PlatformProfile('2026-08-25', [], [], [], [], []));

        self::assertCount(2, $updates);
        self::assertSame(['uri' => 'https://platform.example/.well-known/ucp'], $updates[1]);
    }

    /**
     * Cleanup deletes by expiry and must leave a row with no expiry alone -- a NULL
     * `expires_at` means "cache until told otherwise", and `expires_at < :ts` is false for
     * NULL in SQL, which is the reason the condition is written with an explicit NOT NULL
     * rather than relying on that.
     */
    #[Test]
    public function purgingExpiredEntriesLeavesEntriesWithNoExpiryAlone(): void
    {
        $connection = $this->bootstrappedConnection();
        $repository = new DoctrineDbalPlatformProfileCacheRepository($connection, 600);

        $connection->insert('ucp_platform_profile_cache', [
            'uri' => 'https://stale.example/.well-known/ucp',
            'payload' => json_encode((new PlatformProfile('2026-04-08', [], [], [], [], []))->toArray(), JSON_THROW_ON_ERROR),
            'expires_at' => 100,
        ]);
        $connection->insert('ucp_platform_profile_cache', [
            'uri' => 'https://permanent.example/.well-known/ucp',
            'payload' => json_encode((new PlatformProfile('2026-04-08', [], [], [], [], []))->toArray(), JSON_THROW_ON_ERROR),
            'expires_at' => null,
        ]);

        $repository->purgeExpired(500);

        self::assertNull($repository->find('https://stale.example/.well-known/ucp', allowExpired: true));
        self::assertNotNull($repository->find('https://permanent.example/.well-known/ucp'));
    }

    private function bootstrappedConnection(): Connection
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        (new SchemaBootstrapper($connection))->ensureSchema();

        return $connection;
    }
}
