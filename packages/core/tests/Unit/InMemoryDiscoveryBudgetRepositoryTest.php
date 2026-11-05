<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Internal\Repository\InMemoryDiscoveryBudgetRepository;

final class InMemoryDiscoveryBudgetRepositoryTest extends TestCase
{
    #[Test]
    public function itCountsFetchesPerWindow(): void
    {
        $repository = new InMemoryDiscoveryBudgetRepository();

        self::assertSame(1, $repository->countFetch(100));
        self::assertSame(2, $repository->countFetch(100));
        self::assertSame(1, $repository->countFetch(160));
    }

    #[Test]
    public function itTracksFailuresAndBackoffPerOrigin(): void
    {
        $repository = new InMemoryDiscoveryBudgetRepository();

        self::assertNull($repository->retryAfter('a.example'));
        self::assertSame(1, $repository->recordFailure('a.example', 130));
        self::assertSame(2, $repository->recordFailure('a.example', 160));
        self::assertSame(160, $repository->retryAfter('a.example'));

        $repository->extendBackoff('a.example', 220);
        self::assertSame(220, $repository->retryAfter('a.example'));

        $repository->extendBackoff('unknown.example', 220);
        self::assertNull($repository->retryAfter('unknown.example'), 'extending an origin without failures records nothing');

        $repository->clearFailures('a.example');
        self::assertNull($repository->retryAfter('a.example'));
    }

    #[Test]
    public function itPurgesOldWindowsAndExpiredBackoffs(): void
    {
        $repository = new InMemoryDiscoveryBudgetRepository();
        $repository->countFetch(100);
        $repository->countFetch(200);
        $repository->recordFailure('old.example', 150);
        $repository->recordFailure('new.example', 250);

        $repository->purgeExpired(200);

        self::assertSame(1, $repository->countFetch(100), 'the purged window starts over');
        self::assertSame(2, $repository->countFetch(200), 'the current window is kept');
        self::assertNull($repository->retryAfter('old.example'));
        self::assertSame(250, $repository->retryAfter('new.example'));
    }
}
