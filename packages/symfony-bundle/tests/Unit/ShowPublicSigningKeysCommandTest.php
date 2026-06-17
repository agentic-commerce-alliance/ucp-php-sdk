<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Ucp\Sdk\Model\Security\ManagedSigningKey;
use Ucp\Sdk\Model\Security\PublicSigningKey;
use Ucp\Sdk\Repository\ManagedSigningKeyRepositoryInterface;
use Ucp\Sdk\Service\SigningKeyManagerInterface;
use Ucp\Sdk\Symfony\Command\ShowPublicSigningKeysCommand;

final class ShowPublicSigningKeysCommandTest extends TestCase
{
    #[Test]
    public function itPrintsActivePublicSigningKeysAsJson(): void
    {
        $repository = $this->createMock(ManagedSigningKeyRepositoryInterface::class);
        $repository
            ->method('active')
            ->willReturn([new ManagedSigningKey('key-1', 'public', 'private', 'ES256')]);
        $manager = $this->createMock(SigningKeyManagerInterface::class);
        $manager
            ->method('toPublicKey')
            ->willReturnCallback(static fn (ManagedSigningKey $key): PublicSigningKey => new PublicSigningKey($key->kid, $key->algorithm, curve: 'P-256', x: 'abc', y: 'def'));
        $command = new ShowPublicSigningKeysCommand(
            $repository,
            $manager,
        );

        $tester = new CommandTester($command);
        $status = $tester->execute([]);

        self::assertSame(0, $status);
        self::assertStringContainsString('"kid": "key-1"', $tester->getDisplay());
        self::assertStringContainsString('"crv": "P-256"', $tester->getDisplay());
    }
}
