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
        $repository = $this->createMock(IdempotencyRepositoryInterface::class);
        $repository
            ->expects(self::once())
            ->method('claimPending')
            ->with('abc', 'hash')
            ->willReturn(true);
        $repository
            ->expects(self::once())
            ->method('save')
            ->with(self::callback(static function (IdempotencyRecord $record): bool {
                self::assertSame('completed', $record->status);
                self::assertSame(['ok' => true], $record->responseBody);

                return true;
            }));

        $service = new DefaultIdempotencyService($repository);
        $record = $service->claim('abc', 'hash');
        $service->complete($record, ['ok' => true], 200);
    }

    public function testItRejectsDifferentFingerprints(): void
    {
        $repository = $this->createMock(IdempotencyRepositoryInterface::class);
        $repository
            ->expects(self::once())
            ->method('claimPending')
            ->with('abc', 'hash-2')
            ->willReturn(false);
        $repository
            ->expects(self::once())
            ->method('find')
            ->with('abc')
            ->willReturn(new IdempotencyRecord('abc', 'hash-1', 'completed', ['ok' => true], 200));
        $repository
            ->expects(self::never())
            ->method('save');

        $service = new DefaultIdempotencyService($repository);

        $this->expectException(IdempotencyConflictException::class);
        $service->claim('abc', 'hash-2');
    }

    public function testItRejectsDuplicateFirstClaimsWhenTheRepositoryReportsAClaimCollision(): void
    {
        $repository = $this->createMock(IdempotencyRepositoryInterface::class);
        $repository
            ->expects(self::exactly(2))
            ->method('claimPending')
            ->with('abc', 'hash')
            ->willReturnOnConsecutiveCalls(true, false);
        $repository
            ->expects(self::once())
            ->method('find')
            ->with('abc')
            ->willReturn(new IdempotencyRecord('abc', 'hash'));

        $service = new DefaultIdempotencyService($repository);
        $service->claim('abc', 'hash');

        $this->expectException(IdempotencyConflictException::class);
        $this->expectExceptionMessage('Idempotency key is already being processed.');

        $service->claim('abc', 'hash');
    }
}
