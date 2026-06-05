<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Exception\IdempotencyConflictException;
use Ucp\Sdk\Internal\Service\DefaultIdempotencyService;
use Ucp\Sdk\Model\IdempotencyRecord;
use Ucp\Sdk\Repository\IdempotencyRepositoryInterface;

final class DefaultIdempotencyServiceTest extends TestCase
{
    public function testItClaimsAndCompletesRecords(): void
    {
        $repository = new class () implements IdempotencyRepositoryInterface {
            public ?IdempotencyRecord $record = null;

            public function find(string $key): ?IdempotencyRecord
            {
                return $this->record;
            }

            public function save(IdempotencyRecord $record): void
            {
                $this->record = $record;
            }

            public function delete(string $key): void
            {
                $this->record = null;
            }

            public function purgeExpired(int $olderThanUnixTimestamp): void
            {
            }
        };

        $service = new DefaultIdempotencyService($repository);
        $record = $service->claim('abc', 'hash');
        $service->complete($record, ['ok' => true], 200);

        self::assertNotNull($repository->record);
        self::assertSame('completed', $repository->record->status);
        self::assertSame(['ok' => true], $repository->record->responseBody);
    }

    public function testItRejectsDifferentFingerprints(): void
    {
        $repository = new class () implements IdempotencyRepositoryInterface {
            public ?IdempotencyRecord $record = null;

            public function __construct()
            {
                $this->record = new IdempotencyRecord('abc', 'hash-1', 'completed', ['ok' => true], 200);
            }

            public function find(string $key): ?IdempotencyRecord
            {
                return $this->record;
            }

            public function save(IdempotencyRecord $record): void
            {
                $this->record = $record;
            }

            public function delete(string $key): void
            {
                $this->record = null;
            }

            public function purgeExpired(int $olderThanUnixTimestamp): void
            {
            }
        };

        $service = new DefaultIdempotencyService($repository);

        $this->expectException(IdempotencyConflictException::class);
        $service->claim('abc', 'hash-2');
    }
}
