<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Unit;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Model\Negotiation\NegotiationSession;
use Ucp\Sdk\Symfony\Bridge\DoctrineDbal\DoctrineDbalNegotiationSessionRepository;
use Ucp\Sdk\Symfony\Bridge\DoctrineDbal\SchemaBootstrapper;

final class DoctrineDbalNegotiationSessionRepositoryTest extends TestCase
{
    #[Test]
    public function itStoresAndLoadsNegotiationSessions(): void
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        (new SchemaBootstrapper($connection))->ensureSchema();
        $repository = new DoctrineDbalNegotiationSessionRepository(
            $connection,
        );

        $session = new NegotiationSession(
            'neg-1',
            'https://platform.example/.well-known/ucp',
            '2026-04-08',
            ['dev.ucp.shopping.checkout'],
            ['handler-1'],
            'tenant-a',
            '2026-05-28T00:00:00+00:00',
        );

        $repository->save($session);

        $loadedById = $repository->find('neg-1');
        $loadedByUri = $repository->findByProfileUri('https://platform.example/.well-known/ucp', 'tenant-a');

        self::assertNotNull($loadedById);
        self::assertSame(['dev.ucp.shopping.checkout'], $loadedById->activeCapabilities);
        self::assertSame(['handler-1'], $loadedById->paymentHandlerIds);
        self::assertSame('tenant-a', $loadedByUri?->tenantIdentifier);
        self::assertNull($repository->findByProfileUri('https://platform.example/.well-known/ucp', 'tenant-b'));
    }
}
