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
        $command = new ShowPublicSigningKeysCommand(
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
                    return [];
                }

                public function active(): array
                {
                    return [new ManagedSigningKey('key-1', 'public', 'private', 'ES256')];
                }

                public function purgeRetired(string $olderThanIso8601): void
                {
                }
            },
            new class () implements SigningKeyManagerInterface {
                public function generate(string $kid, string $algorithm = 'ES256'): ManagedSigningKey
                {
                    throw new \RuntimeException('Not used in this test.');
                }

                public function toPublicKey(ManagedSigningKey $key): PublicSigningKey
                {
                    return new PublicSigningKey($key->kid, $key->algorithm, curve: 'P-256', x: 'abc', y: 'def');
                }

                public function publicKeyFromJwk(array $jwk): PublicSigningKey
                {
                    throw new \RuntimeException('Not used in this test.');
                }
            },
        );

        $tester = new CommandTester($command);
        $status = $tester->execute([]);

        self::assertSame(0, $status);
        self::assertStringContainsString('"kid": "key-1"', $tester->getDisplay());
        self::assertStringContainsString('"crv": "P-256"', $tester->getDisplay());
    }
}
