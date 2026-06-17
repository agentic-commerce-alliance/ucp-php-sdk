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
    public function itFailsClosedWhenStoredReplayableResponseBodyIsPlaintextJson(): void
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

        $connection->insert('ucp_idempotency', [
            'idempotency_key' => 'legacy-idem',
            'fingerprint' => 'fp-1',
            'status' => 'completed',
            'response_body' => '{"ok":true}',
            'status_code' => 201,
            'replayable' => 1,
            'expires_at' => time() + 600,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Encrypted private key payload is malformed.');

        $repository->find('legacy-idem');
    }

    #[Test]
    public function itFailsClosedWhenReplayableResponseBodyCannotBeDecryptedWithCurrentSecret(): void
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        (new SchemaBootstrapper($connection))->ensureSchema();
        $key = 'rotated-secret-idem';

        $connection->insert('ucp_idempotency', [
            'idempotency_key' => $key,
            'fingerprint' => 'fp-1',
            'status' => 'completed',
            'response_body' => (new DefaultPrivateKeyEncryptor('old-secret'))->encrypt('{"ok":true}', 'idempotency:' . $key),
            'status_code' => 201,
            'replayable' => 1,
            'expires_at' => time() + 600,
        ]);

        $repository = new DoctrineDbalIdempotencyRepository(
            $connection,
            new DefaultPrivateKeyEncryptor('new-secret'),
            86400,
            1024,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to decrypt private key material.');

        $repository->find($key);
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
}
