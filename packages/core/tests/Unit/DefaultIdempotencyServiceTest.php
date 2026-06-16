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
            ->method('find')
            ->with('abc')
            ->willReturn(null);
        $repository
            ->expects($this->exactly(2))
            ->method('save')
            ->willReturnCallback(static function (IdempotencyRecord $record) use (&$savedRecords): void {
                $savedRecords[] = $record;
            });
        $service = new DefaultIdempotencyService($repository);
        $record = $service->claim('abc', 'hash');
        $service->complete($record, ['ok' => true], 200);

        self::assertCount(2, $savedRecords);
        self::assertSame('completed', $savedRecords[1]->status);
        self::assertSame(['ok' => true], $savedRecords[1]->responseBody);
    }

    public function testItRejectsDifferentFingerprints(): void
    {
        $repository = $this->createMock(IdempotencyRepositoryInterface::class);
        $repository
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
}
