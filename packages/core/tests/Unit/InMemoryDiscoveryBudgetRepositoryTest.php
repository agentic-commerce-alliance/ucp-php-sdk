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
    public function itTracksFailuresAndBackoffPerUri(): void
    {
        $repository = new InMemoryDiscoveryBudgetRepository();

        self::assertNull($repository->retryAfter('https://a.example/.well-known/ucp'));
        self::assertSame(1, $repository->recordFailure('https://a.example/.well-known/ucp', 130));
        self::assertSame(2, $repository->recordFailure('https://a.example/.well-known/ucp', 160));
        self::assertSame(160, $repository->retryAfter('https://a.example/.well-known/ucp'));

        $repository->extendBackoff('https://a.example/.well-known/ucp', 220);
        self::assertSame(220, $repository->retryAfter('https://a.example/.well-known/ucp'));

        $repository->extendBackoff('https://unknown.example/.well-known/ucp', 220);
        self::assertNull($repository->retryAfter('https://unknown.example/.well-known/ucp'), 'extending a URL without failures records nothing');

        $repository->clearFailures('https://a.example/.well-known/ucp');
        self::assertNull($repository->retryAfter('https://a.example/.well-known/ucp'));
    }

    #[Test]
    public function itPurgesOldWindowsAndExpiredBackoffs(): void
    {
        $repository = new InMemoryDiscoveryBudgetRepository();
        $repository->countFetch(100);
        $repository->countFetch(200);
        $repository->recordFailure('https://old.example/.well-known/ucp', 150);
        $repository->recordFailure('https://new.example/.well-known/ucp', 250);

        $repository->purgeExpired(200);

        self::assertSame(1, $repository->countFetch(100), 'the purged window starts over');
        self::assertSame(2, $repository->countFetch(200), 'the current window is kept');
        self::assertNull($repository->retryAfter('https://old.example/.well-known/ucp'));
        self::assertSame(250, $repository->retryAfter('https://new.example/.well-known/ucp'));
    }
}
