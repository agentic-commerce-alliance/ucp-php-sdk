<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Ucp\Sdk\Model\Security\ManagedSigningKey;
use Ucp\Sdk\Repository\ManagedSigningKeyRepositoryInterface;
use Ucp\Sdk\Service\SigningKeyManagerInterface;
use Ucp\Sdk\Symfony\Command\GenerateSigningKeyCommand;

final class GenerateSigningKeyCommandTest extends TestCase
{
    #[Test]
    public function itGeneratesAndStoresSigningKeys(): void
    {
        $state = new SavedKeyState();
        $command = new GenerateSigningKeyCommand(
            new class () implements SigningKeyManagerInterface {
                public function generate(string $kid, string $algorithm = 'ES256'): ManagedSigningKey
                {
                    return new ManagedSigningKey($kid, 'public', 'private', $algorithm);
                }

                public function toPublicKey(ManagedSigningKey $key): \Ucp\Sdk\Model\Security\PublicSigningKey
                {
                    throw new \RuntimeException('Not used in this test.');
                }

                public function publicKeyFromJwk(array $jwk): \Ucp\Sdk\Model\Security\PublicSigningKey
                {
                    throw new \RuntimeException('Not used in this test.');
                }
            },
            new class ($state) implements ManagedSigningKeyRepositoryInterface {
                public function __construct(private SavedKeyState $state)
                {
                }

                public function saveManaged(ManagedSigningKey $key): void
                {
                    $this->state->savedKey = $key;
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
                    return [];
                }

                public function purgeRetired(string $olderThanIso8601): void
                {
                }
            },
        );

        $tester = new CommandTester($command);
        $status = $tester->execute([
            '--kid' => 'command-key',
            '--algorithm' => 'ES384',
        ]);

        self::assertSame(0, $status);
        self::assertInstanceOf(ManagedSigningKey::class, $state->savedKey);
        self::assertSame('command-key', $state->savedKey->kid);
        self::assertStringContainsString('Generated signing key "command-key" using ES384.', $tester->getDisplay());
    }
}

final class SavedKeyState
{
    public ?ManagedSigningKey $savedKey = null;
}
