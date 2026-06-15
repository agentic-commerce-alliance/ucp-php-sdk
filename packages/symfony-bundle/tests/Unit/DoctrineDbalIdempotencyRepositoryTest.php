<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Unit;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Model\IdempotencyRecord;
use Ucp\Sdk\Symfony\Bridge\DefaultStorage\DefaultPrivateKeyEncryptor;
use Ucp\Sdk\Symfony\Bridge\DoctrineDbal\DoctrineDbalIdempotencyRepository;
use Ucp\Sdk\Symfony\Bridge\DoctrineDbal\SchemaBootstrapper;

final class DoctrineDbalIdempotencyRepositoryTest extends TestCase
{
    #[Test]
    public function itEncryptsReplayableResponseBodiesAtRest(): void
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        (new SchemaBootstrapper($connection))->ensureSchema();
        $repository = new DoctrineDbalIdempotencyRepository(
            $connection,
            new DefaultPrivateKeyEncryptor('test-secret'),
            86400,
            1024,
        );

        $record = new IdempotencyRecord('idem-1', 'fp-1', 'completed', ['ok' => true], 201);
        $repository->save($record);

        $row = $connection->fetchAssociative('SELECT * FROM ucp_idempotency WHERE idempotency_key = :key', ['key' => 'idem-1']);
        self::assertIsArray($row);
        self::assertNotSame('{"ok":true}', $row['response_body']);

        $loaded = $repository->find('idem-1');

        self::assertNotNull($loaded);
        self::assertSame(['ok' => true], $loaded->responseBody);
        self::assertTrue($loaded->replayable);
    }

    #[Test]
    public function itMarksOversizedResponsesAsNonReplayableAndPurgesExpiredRows(): void
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        (new SchemaBootstrapper($connection))->ensureSchema();
        $repository = new DoctrineDbalIdempotencyRepository(
            $connection,
            new DefaultPrivateKeyEncryptor('test-secret'),
            86400,
            8,
        );

        $record = new IdempotencyRecord('idem-2', 'fp-2', 'completed', ['message' => 'too large'], 201);
        $repository->save($record);

        $loaded = $repository->find('idem-2');
        self::assertNotNull($loaded);
        self::assertNull($loaded->responseBody);
        self::assertFalse($loaded->replayable);

        $connection->executeStatement(
            'UPDATE ucp_idempotency SET expires_at = :expires_at WHERE idempotency_key = :key',
            ['expires_at' => time() - 5, 'key' => 'idem-2'],
        );

        $repository->purgeExpired(time());

        self::assertNull($repository->find('idem-2'));
    }

    #[Test]
    public function itReportsDuplicatePendingClaimsWithoutUpdatingTheExistingRecord(): void
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        (new SchemaBootstrapper($connection))->ensureSchema();
        $repository = new DoctrineDbalIdempotencyRepository(
            $connection,
            new DefaultPrivateKeyEncryptor('test-secret'),
            86400,
            1024,
        );

        self::assertTrue($repository->claimPending('idem-3', 'fp-1'));
        self::assertFalse($repository->claimPending('idem-3', 'fp-2'));

        $loaded = $repository->find('idem-3');

        self::assertNotNull($loaded);
        self::assertSame('fp-1', $loaded->fingerprint);
        self::assertSame('pending', $loaded->status);
    }
}
