<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Unit;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Model\Negotiation\NegotiationSession;
use Ucp\Sdk\Symfony\Bridge\DoctrineDbal\DoctrineDbalNegotiationSessionRepository;
use Ucp\Sdk\Symfony\Bridge\DoctrineDbal\SchemaBootstrapper;

final class DoctrineDbalNegotiationSessionRepositoryTest extends TestCase
{
    #[Test]
    public function itStoresAndLoadsNegotiationSessions(): void
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        (new SchemaBootstrapper($connection))->ensureSchema();
        $repository = new DoctrineDbalNegotiationSessionRepository(
            $connection,
        );

        $session = new NegotiationSession(
            'neg-1',
            'https://platform.example/.well-known/ucp',
            '2026-04-08',
            ['dev.ucp.shopping.checkout'],
            ['handler-1'],
            'tenant-a',
            '2026-05-28T00:00:00+00:00',
        );

        $repository->save($session);

        $loadedById = $repository->find('neg-1');
        $loadedByUri = $repository->findByProfileUri('https://platform.example/.well-known/ucp', 'tenant-a');

        self::assertNotNull($loadedById);
        self::assertSame(['dev.ucp.shopping.checkout'], $loadedById->activeCapabilities);
        self::assertSame(['handler-1'], $loadedById->paymentHandlerIds);
        self::assertSame('tenant-a', $loadedByUri?->tenantIdentifier);
        self::assertNull($repository->findByProfileUri('https://platform.example/.well-known/ucp', 'tenant-b'));
    }

    /**
     * An expired session must read as absent through both lookups rather than through one
     * of them. They are separate queries with separately written expiry checks, so a peer
     * holding a stale session id would otherwise still resolve it by profile URI.
     */
    #[Test]
    public function anExpiredSessionIsInvisibleToBothLookups(): void
    {
        $connection = $this->bootstrappedConnection();
        // A negative TTL puts expires_at in the past at save time, which is the only way
        // to observe expiry without waiting for it.
        $repository = new DoctrineDbalNegotiationSessionRepository($connection, -60);

        $repository->save(new NegotiationSession(
            'neg-expired',
            'https://platform.example/.well-known/ucp',
            '2026-08-25',
            ['dev.ucp.shopping.checkout'],
        ));

        self::assertNull($repository->find('neg-expired'));
        self::assertNull($repository->findByProfileUri('https://platform.example/.well-known/ucp'));
    }

    #[Test]
    public function reSavingASessionRefreshesItInPlace(): void
    {
        $connection = $this->bootstrappedConnection();
        $repository = new DoctrineDbalNegotiationSessionRepository($connection, 600);

        $repository->save(new NegotiationSession('neg-1', 'https://platform.example/.well-known/ucp', '2026-04-08', []));
        $repository->save(new NegotiationSession('neg-1', 'https://platform.example/.well-known/ucp', '2026-08-25', ['dev.ucp.shopping.cart']));

        $reloaded = $repository->find('neg-1');

        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM ucp_negotiation_sessions'));
        self::assertNotNull($reloaded);
        self::assertSame('2026-08-25', $reloaded->protocolVersion);
        self::assertSame(['dev.ucp.shopping.cart'], $reloaded->activeCapabilities);
    }

    /**
     * Only reachable when another request writes the same session id between this one's
     * UPDATE and its INSERT. Stubbed, because a single-threaded test cannot interleave
     * two writers.
     */
    #[Test]
    public function aSessionInsertedConcurrentlyByAnotherRequestIsUpdatedRatherThanRaising(): void
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

        $repository = new DoctrineDbalNegotiationSessionRepository($connection, 600);

        $repository->save(new NegotiationSession('neg-1', 'https://platform.example/.well-known/ucp', '2026-08-25', []));

        self::assertCount(2, $updates);
        self::assertSame(['id' => 'neg-1'], $updates[1]);
    }

    #[Test]
    public function purgingExpiredSessionsLeavesLiveOnesAlone(): void
    {
        $connection = $this->bootstrappedConnection();
        $live = new DoctrineDbalNegotiationSessionRepository($connection, 600);
        $stale = new DoctrineDbalNegotiationSessionRepository($connection, -60);

        $live->save(new NegotiationSession('neg-live', 'https://live.example/.well-known/ucp', '2026-08-25', []));
        $stale->save(new NegotiationSession('neg-stale', 'https://stale.example/.well-known/ucp', '2026-08-25', []));

        $live->purgeExpired(time());

        self::assertSame(
            0,
            (int) $connection->fetchOne('SELECT COUNT(*) FROM ucp_negotiation_sessions WHERE id = ?', ['neg-stale']),
        );
        self::assertNotNull($live->find('neg-live'));
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
