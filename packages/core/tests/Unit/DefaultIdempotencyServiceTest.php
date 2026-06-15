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
        /** @var list<IdempotencyRecord> $savedRecords */
        $savedRecords = [];
        $repository = $this->createMock(IdempotencyRepositoryInterface::class);
        $repository
            ->expects($this->once())
            ->method('claimPending')
            ->with('abc', 'hash')
            ->willReturn(true);
        $repository
            ->expects($this->once())
            ->method('save')
            ->willReturnCallback(static function (IdempotencyRecord $record) use (&$savedRecords): void {
                $savedRecords[] = $record;
            });
        $service = new DefaultIdempotencyService($repository);
        $record = $service->claim('abc', 'hash');
        $service->complete($record, ['ok' => true], 200);

        self::assertCount(1, $savedRecords);
        self::assertSame('completed', $savedRecords[0]->status);
        self::assertSame(['ok' => true], $savedRecords[0]->responseBody);
    }

    public function testItRejectsDifferentFingerprints(): void
    {
        $repository = $this->createMock(IdempotencyRepositoryInterface::class);
        $repository
            ->expects($this->once())
            ->method('claimPending')
            ->with('abc', 'hash-2')
            ->willReturn(false);
        $repository
            ->expects($this->once())
            ->method('find')
            ->with('abc')
            ->willReturn(new IdempotencyRecord('abc', 'hash-1', 'completed', ['ok' => true], 200));
        $repository
            ->expects($this->never())
            ->method('save');
        $service = new DefaultIdempotencyService($repository);

        $this->expectException(IdempotencyConflictException::class);
        $service->claim('abc', 'hash-2');
    }

    public function testItRejectsDuplicateFirstClaimsWhenTheRepositoryReportsAClaimCollision(): void
    {
        $repository = new class () implements IdempotencyRepositoryInterface {
            public ?IdempotencyRecord $record = null;

            private int $claimAttempts = 0;

            public function claimPending(string $key, string $fingerprint): bool
            {
                ++$this->claimAttempts;

                if ($this->claimAttempts === 1) {
                    $this->record = new IdempotencyRecord($key, $fingerprint);

                    return true;
                }

                return false;
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
        $service->claim('abc', 'hash');

        $this->expectException(IdempotencyConflictException::class);
        $this->expectExceptionMessage('Idempotency key is already being processed.');

        $service->claim('abc', 'hash');
    }
}
