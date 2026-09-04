<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Unit;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
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

    /**
     * save() is an upsert written as UPDATE-then-INSERT, and the early return after a
     * successful UPDATE was never taken -- every existing test saved each nonce once. It
     * matters because the alternative path is an INSERT that would violate the unique
     * key: if the return were dropped, re-saving a nonce would raise instead of
     * refreshing it.
     */
    #[Test]
    public function reSavingANonceRefreshesItRatherThanInsertingAgain(): void
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        (new SchemaBootstrapper($connection))->ensureSchema();
        $repository = new DoctrineDbalSignatureNonceRepository($connection);

        $repository->save('scope-a', 'kid-1', 'hash-1', 100);
        $repository->save('scope-a', 'kid-1', 'hash-1', 900);

        self::assertSame(
            1,
            (int) $connection->fetchOne('SELECT COUNT(*) FROM ucp_signature_nonces WHERE signature_hash = ?', ['hash-1']),
            'The second save must update the row rather than add one.',
        );
        self::assertSame(
            900,
            (int) $connection->fetchOne('SELECT created_at FROM ucp_signature_nonces WHERE signature_hash = ?', ['hash-1']),
        );
    }

    /**
     * The remaining branch is only reachable under concurrency: the UPDATE finds nothing,
     * and by the time the INSERT runs another request has written the same nonce. No
     * single-threaded test can produce that interleaving, so the connection is stubbed to
     * produce it -- an UPDATE reporting zero rows followed by an INSERT that violates the
     * unique key.
     *
     * Worth covering rather than dismissing as unreachable: this is exactly what two
     * concurrent requests carrying the same signature do, and if the catch did not
     * recover the second one would surface a driver exception instead of being handled as
     * a replay.
     */
    #[Test]
    public function aNonceInsertedConcurrentlyByAnotherRequestIsUpdatedRatherThanRaising(): void
    {
        $connection = $this->createMock(Connection::class);
        $updates = [];
        $connection
            ->method('update')
            ->willReturnCallback(static function (string $table, array $data, array $criteria) use (&$updates): int {
                $updates[] = [$table, $data, $criteria];

                // Zero on the first attempt -- the row does not exist yet -- and one on the
                // retry, by which time the concurrent INSERT has created it.
                return count($updates) === 1 ? 0 : 1;
            });
        $connection
            ->method('insert')
            ->willThrowException($this->uniqueConstraintViolation());

        $repository = new DoctrineDbalSignatureNonceRepository($connection);

        $repository->save('scope-a', 'kid-1', 'hash-1', 100);

        self::assertCount(2, $updates, 'The failed insert must be followed by a second update.');
        self::assertSame(['created_at' => 100], $updates[1][1]);
        self::assertSame(
            ['scope' => 'scope-a', 'kid' => 'kid-1', 'signature_hash' => 'hash-1'],
            $updates[1][2],
            'The retry must target the same nonce, not a broader set of rows.',
        );
    }

    private function uniqueConstraintViolation(): UniqueConstraintViolationException
    {
        return (new \ReflectionClass(UniqueConstraintViolationException::class))->newInstanceWithoutConstructor();
    }
}
