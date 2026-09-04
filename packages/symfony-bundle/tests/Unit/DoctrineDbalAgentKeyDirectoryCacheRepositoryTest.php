<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Unit;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Model\Security\AgentKeyDirectory;
use Ucp\Sdk\Model\Security\PublicSigningKey;
use Ucp\Sdk\Symfony\Bridge\DoctrineDbal\DoctrineDbalAgentKeyDirectoryCacheRepository;
use Ucp\Sdk\Symfony\Bridge\DoctrineDbal\SchemaBootstrapper;

/**
 * Covers the storage half of `Signature-Agent` resolution.
 *
 * This cache is what stops every inbound signed request from becoming an outbound one, so
 * "a cached key is reused without a second fetch" is only true if the round trip through
 * the table actually returns a usable key set. The fetcher's own test proves it consults
 * the cache; this proves there is something worth consulting.
 */
final class DoctrineDbalAgentKeyDirectoryCacheRepositoryTest extends TestCase
{
    private const URI = 'https://agent.example/.well-known/http-message-signatures-directory';

    #[Test]
    public function itRoundTripsADirectoryThroughStorage(): void
    {
        $connection = $this->bootstrappedConnection();
        $repository = new DoctrineDbalAgentKeyDirectoryCacheRepository($connection, 600);

        $repository->save(self::URI, $this->directory('kid-1'));
        $loaded = $repository->find(self::URI);

        self::assertNotNull($loaded);
        self::assertSame(self::URI, $loaded->uri);
        self::assertCount(1, $loaded->keys);
        self::assertSame('kid-1', $loaded->keys[0]->kid);
    }

    #[Test]
    public function findReturnsNullForADirectoryThatWasNeverCached(): void
    {
        $repository = new DoctrineDbalAgentKeyDirectoryCacheRepository($this->bootstrappedConnection(), 600);

        self::assertNull($repository->find('https://never-seen.example/directory'));
    }

    /**
     * An agent rotating its keys re-publishes at the same URL, so re-saving has to replace
     * the cached copy in place. The early return after a successful UPDATE is what makes
     * that work -- without it the next branch is an INSERT that violates the unique key on
     * `uri`, so the second fetch of any directory would raise.
     */
    #[Test]
    public function reSavingADirectoryReplacesTheCachedCopy(): void
    {
        $connection = $this->bootstrappedConnection();
        $repository = new DoctrineDbalAgentKeyDirectoryCacheRepository($connection, 600);

        $repository->save(self::URI, $this->directory('kid-1'));
        $repository->save(self::URI, $this->directory('kid-2'));

        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM ucp_agent_key_directory_cache'));
        self::assertSame('kid-2', $repository->find(self::URI)?->keys[0]->kid);
    }

    /**
     * Reachable only when another request caches the same URL between this one's UPDATE and
     * its INSERT -- which two concurrent requests from the same bot do. Stubbed, because no
     * single-threaded test can interleave two writers.
     */
    #[Test]
    public function aDirectoryCachedConcurrentlyIsUpdatedRatherThanRaising(): void
    {
        $connection = $this->createMock(Connection::class);
        $criteria = [];
        $connection
            ->method('update')
            ->willReturnCallback(static function (string $table, array $data, array $where) use (&$criteria): int {
                $criteria[] = $where;

                return count($criteria) === 1 ? 0 : 1;
            });
        $connection
            ->method('insert')
            ->willThrowException((new \ReflectionClass(UniqueConstraintViolationException::class))->newInstanceWithoutConstructor());

        $repository = new DoctrineDbalAgentKeyDirectoryCacheRepository($connection, 600);

        $repository->save(self::URI, $this->directory('kid-1'));

        self::assertCount(2, $criteria, 'The failed insert must be followed by a second update.');
        self::assertSame(['uri' => self::URI], $criteria[1]);
    }

    /**
     * An expired directory is absent by default and readable on request. Both halves matter:
     * the fetcher refuses a stale key set for a normal verification, and falls back to it
     * when the agent's key server is briefly unreachable -- the alternative being to refuse
     * every signed request from that agent because its keys had a bad minute.
     */
    #[Test]
    public function anExpiredDirectoryIsHiddenUnlessStaleReadsAreAskedFor(): void
    {
        $connection = $this->bootstrappedConnection();
        // A negative TTL puts expires_at in the past at save time, which is the only way to
        // observe expiry without waiting for it.
        (new DoctrineDbalAgentKeyDirectoryCacheRepository($connection, -60))->save(self::URI, $this->directory('kid-1'));
        $repository = new DoctrineDbalAgentKeyDirectoryCacheRepository($connection, 600);

        self::assertNull($repository->find(self::URI));
        self::assertSame('kid-1', $repository->find(self::URI, allowExpired: true)?->keys[0]->kid);
    }

    #[Test]
    public function deletingReportsWhetherThereWasAnythingToDelete(): void
    {
        $repository = new DoctrineDbalAgentKeyDirectoryCacheRepository($this->bootstrappedConnection(), 600);
        $repository->save(self::URI, $this->directory('kid-1'));

        self::assertTrue($repository->delete(self::URI));
        self::assertFalse($repository->delete(self::URI), 'A second delete removed nothing.');
        self::assertNull($repository->find(self::URI));
    }

    #[Test]
    public function purgingExpiredDirectoriesLeavesLiveOnesAlone(): void
    {
        $connection = $this->bootstrappedConnection();
        $live = new DoctrineDbalAgentKeyDirectoryCacheRepository($connection, 600);
        $stale = new DoctrineDbalAgentKeyDirectoryCacheRepository($connection, -60);

        $live->save('https://live.example/directory', $this->directory('kid-live'));
        $stale->save('https://stale.example/directory', $this->directory('kid-stale'));

        $live->purgeExpired(time());

        self::assertNull($live->find('https://stale.example/directory', allowExpired: true));
        self::assertNotNull($live->find('https://live.example/directory'));
    }

    /**
     * A key type this SDK cannot verify with must not discard the rest of the set -- the same
     * rule a platform profile follows. Storage has to preserve that: an unusable entry is
     * dropped on the way back out rather than failing the read.
     */
    #[Test]
    public function anUnusableKeyInStorageDoesNotDiscardTheUsableOnes(): void
    {
        $connection = $this->bootstrappedConnection();
        $repository = new DoctrineDbalAgentKeyDirectoryCacheRepository($connection, 600);

        $connection->insert('ucp_agent_key_directory_cache', [
            'uri' => self::URI,
            'payload' => json_encode([
                'keys' => [
                    ['kty' => 'XYZ', 'kid' => 'unusable'],
                    $this->jwk('kid-usable'),
                ],
            ], JSON_THROW_ON_ERROR),
            'expires_at' => time() + 600,
        ]);

        $loaded = $repository->find(self::URI);

        self::assertNotNull($loaded);
        self::assertCount(1, $loaded->keys);
        self::assertSame('kid-usable', $loaded->keys[0]->kid);
    }

    private function directory(string $kid): AgentKeyDirectory
    {
        return new AgentKeyDirectory(self::URI, [PublicSigningKey::fromJwk($this->jwk($kid))]);
    }

    /**
     * @return array<string, string>
     */
    private function jwk(string $kid): array
    {
        // A real P-256 point, so tryFromJwk() reconstructs a usable key rather than skipping
        // the entry and quietly emptying the set this test is about.
        return [
            'kty' => 'EC',
            'crv' => 'P-256',
            'kid' => $kid,
            'x' => 'f83OJ3D2xF1Bg8vub9tLe1gHMzV76e8Tus9uPHvRVEU',
            'y' => 'x_FEzRu9m36HLN_tue659LNpXW6pCyStikYjKIWI5a0',
        ];
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
