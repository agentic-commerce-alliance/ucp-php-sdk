<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Unit;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Symfony\Bridge\DoctrineDbal\DoctrineDbalDiscoveryBudgetRepository;
use Ucp\Sdk\Symfony\Bridge\DoctrineDbal\SchemaBootstrapper;

final class DoctrineDbalDiscoveryBudgetRepositoryTest extends TestCase
{
    #[Test]
    public function itCountsFetchesPerWindowAcrossCalls(): void
    {
        $repository = new DoctrineDbalDiscoveryBudgetRepository($this->connection());

        self::assertSame(1, $repository->countFetch(100));
        self::assertSame(2, $repository->countFetch(100));
        self::assertSame(1, $repository->countFetch(160));
    }

    #[Test]
    public function itRecordsExtendsAndClearsBackoffPerOrigin(): void
    {
        $repository = new DoctrineDbalDiscoveryBudgetRepository($this->connection());

        self::assertNull($repository->retryAfter('a.example'));
        self::assertSame(1, $repository->recordFailure('a.example', 130));
        self::assertSame(2, $repository->recordFailure('a.example', 160));
        self::assertSame(160, $repository->retryAfter('a.example'));

        $repository->extendBackoff('a.example', 220);
        self::assertSame(220, $repository->retryAfter('a.example'));

        $repository->clearFailures('a.example');
        self::assertNull($repository->retryAfter('a.example'));
    }

    #[Test]
    public function itPurgesOldWindowsAndExpiredBackoffs(): void
    {
        $repository = new DoctrineDbalDiscoveryBudgetRepository($this->connection());
        $repository->countFetch(100);
        $repository->countFetch(200);
        $repository->recordFailure('old.example', 150);
        $repository->recordFailure('new.example', 250);

        $repository->purgeExpired(200);

        self::assertSame(1, $repository->countFetch(100));
        self::assertSame(2, $repository->countFetch(200));
        self::assertNull($repository->retryAfter('old.example'));
        self::assertSame(250, $repository->retryAfter('new.example'));
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        (new SchemaBootstrapper($connection))->ensureSchema();

        return $connection;
    }
}
