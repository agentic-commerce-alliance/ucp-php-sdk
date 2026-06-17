<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Ucp\Sdk\Model\Security\ManagedSigningKey;
use Ucp\Sdk\Repository\ManagedSigningKeyRepositoryInterface;
use Ucp\Sdk\Symfony\Command\ListSigningKeysCommand;

final class ListSigningKeysCommandTest extends TestCase
{
    #[Test]
    public function itPrintsManagedSigningKeysAsJson(): void
    {
        $repository = $this->createMock(ManagedSigningKeyRepositoryInterface::class);
        $repository
            ->method('allManaged')
            ->willReturn([
                new ManagedSigningKey('key-1', 'public', 'private', 'ES256', createdAt: '2026-05-28T00:00:00+00:00'),
            ]);
        $command = new ListSigningKeysCommand(
            $repository,
        );

        $tester = new CommandTester($command);
        $status = $tester->execute([]);

        self::assertSame(0, $status);
        self::assertStringContainsString('"kid": "key-1"', $tester->getDisplay());
        self::assertStringContainsString('"algorithm": "ES256"', $tester->getDisplay());
    }
}
