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
use Ucp\Sdk\Symfony\Command\GenerateSigningKeyCommand;

final class GenerateSigningKeyCommandTest extends TestCase
{
    #[Test]
    public function itGeneratesAndStoresSigningKeys(): void
    {
        $state = new SavedKeyState();
        $manager = new CapturingSigningKeyManager();
        $command = new GenerateSigningKeyCommand(
            $manager,
            new SavedKeyRepository($state),
        );

        $tester = new CommandTester($command);
        $status = $tester->execute([
            '--kid' => 'command-key',
            '--algorithm' => 'ES384',
        ]);

        self::assertSame(0, $status);
        self::assertInstanceOf(ManagedSigningKey::class, $state->savedKey);
        self::assertSame('command-key', $state->savedKey->kid);
        self::assertSame('command-key', $manager->generatedKid);
        self::assertSame('ES384', $manager->generatedAlgorithm);
        self::assertStringContainsString('Generated signing key "command-key" using ES384.', $tester->getDisplay());
    }

    #[Test]
    public function itUsesConfiguredDefaultsAndAppliesRetirementInterval(): void
    {
        $state = new SavedKeyState();
        $manager = new CapturingSigningKeyManager();
        $command = new GenerateSigningKeyCommand(
            $manager,
            new SavedKeyRepository($state),
            'configured-kid',
            'ES384',
            'P1D',
        );

        $tester = new CommandTester($command);
        $status = $tester->execute([]);

        self::assertSame(0, $status);
        self::assertSame('configured-kid', $manager->generatedKid);
        self::assertSame('ES384', $manager->generatedAlgorithm);
        self::assertInstanceOf(ManagedSigningKey::class, $state->savedKey);
        self::assertSame('configured-kid', $state->savedKey->kid);
        self::assertSame('ES384', $state->savedKey->algorithm);
        self::assertNotNull($state->savedKey->retireAt);
        self::assertGreaterThan(time(), strtotime($state->savedKey->retireAt) ?: 0);
    }
}

final class SavedKeyState
{
    public ?ManagedSigningKey $savedKey = null;
}

final class CapturingSigningKeyManager implements SigningKeyManagerInterface
{
    public ?string $generatedKid = null;

    public ?string $generatedAlgorithm = null;

    public function generate(string $kid, string $algorithm = 'ES256'): ManagedSigningKey
    {
        $this->generatedKid = $kid;
        $this->generatedAlgorithm = $algorithm;

        return new ManagedSigningKey($kid, 'public', 'private', $algorithm);
    }

    public function toPublicKey(ManagedSigningKey $key): PublicSigningKey
    {
        throw new \RuntimeException('Not used in this test.');
    }

    public function publicKeyFromJwk(array $jwk): PublicSigningKey
    {
        throw new \RuntimeException('Not used in this test.');
    }
}

final class SavedKeyRepository implements ManagedSigningKeyRepositoryInterface
{
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
}
