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
        $command = new ListSigningKeysCommand(
            new class () implements ManagedSigningKeyRepositoryInterface {
                public function saveManaged(ManagedSigningKey $key): void
                {
                }

                public function findManaged(string $kid): ?ManagedSigningKey
                {
                    return null;
                }

                public function deleteManaged(string $kid): bool
                {
                    return false;
                }

                public function allManaged(): array
                {
                    return [
                        new ManagedSigningKey('key-1', 'public', 'private', 'ES256', createdAt: '2026-05-28T00:00:00+00:00'),
                    ];
                }

                public function active(): array
                {
                    return [];
                }

                public function purgeRetired(string $olderThanIso8601): void
                {
                }
            },
        );

        $tester = new CommandTester($command);
        $status = $tester->execute([]);

        self::assertSame(0, $status);
        self::assertStringContainsString('"kid": "key-1"', $tester->getDisplay());
        self::assertStringContainsString('"algorithm": "ES256"', $tester->getDisplay());
    }
}
