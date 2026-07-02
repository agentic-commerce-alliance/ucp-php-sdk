<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Ucp\Sdk\Model\Security\ManagedSigningKey;
use Ucp\Sdk\Model\Security\PublicSigningKey;
use Ucp\Sdk\Repository\ManagedSigningKeyRepositoryInterface;
use Ucp\Sdk\Repository\TenantAwareManagedSigningKeyRepositoryInterface;
use Ucp\Sdk\Service\SigningKeyManagerInterface;
use Ucp\Sdk\Symfony\Command\DeleteSigningKeyCommand;
use Ucp\Sdk\Symfony\Command\GenerateSigningKeyCommand;
use Ucp\Sdk\Symfony\Command\ListSigningKeysCommand;
use Ucp\Sdk\Symfony\Command\RetireSigningKeyCommand;

final class SigningKeyTenantCommandTest extends TestCase
{
    #[Test]
    public function itGeneratesForTheGivenTenant(): void
    {
        $repository = new InMemoryTenantSigningKeyRepository();
        $command = new GenerateSigningKeyCommand(new StubSigningKeyManager(), $repository);

        $status = (new CommandTester($command))->execute(['--kid' => 'k1', '--tenant' => 'tenant-a']);

        self::assertSame(0, $status);
        self::assertArrayHasKey('k1', $repository->byTenant['tenant-a'] ?? []);
        self::assertArrayNotHasKey('k1', $repository->byTenant[''] ?? []);
    }

    #[Test]
    public function itFallsBackToTheGlobalScopeWithoutTenant(): void
    {
        $repository = new InMemoryTenantSigningKeyRepository();
        $command = new GenerateSigningKeyCommand(new StubSigningKeyManager(), $repository);

        (new CommandTester($command))->execute(['--kid' => 'k1']);

        self::assertArrayHasKey('k1', $repository->byTenant[''] ?? []);
    }

    #[Test]
    public function itListsOnlyTheTenantKeys(): void
    {
        $repository = new InMemoryTenantSigningKeyRepository();
        $repository->saveManagedForTenant('tenant-a', new ManagedSigningKey('a-key', 'pub', 'priv'));
        $repository->saveManagedForTenant('tenant-b', new ManagedSigningKey('b-key', 'pub', 'priv'));

        $tester = new CommandTester(new ListSigningKeysCommand($repository));
        $tester->execute(['--tenant' => 'tenant-a']);

        self::assertStringContainsString('a-key', $tester->getDisplay());
        self::assertStringNotContainsString('b-key', $tester->getDisplay());
    }

    #[Test]
    public function itRetiresATenantKey(): void
    {
        $repository = new InMemoryTenantSigningKeyRepository();
        $repository->saveManagedForTenant('tenant-a', new ManagedSigningKey('a-key', 'pub', 'priv'));

        $status = (new CommandTester(new RetireSigningKeyCommand($repository)))
            ->execute(['--kid' => 'a-key', '--tenant' => 'tenant-a']);

        self::assertSame(0, $status);
        self::assertSame('retired', $repository->findManagedForTenant('tenant-a', 'a-key')?->status);
    }

    #[Test]
    public function itFailsToRetireAMissingKey(): void
    {
        $status = (new CommandTester(new RetireSigningKeyCommand(new InMemoryTenantSigningKeyRepository())))
            ->execute(['--kid' => 'nope', '--tenant' => 'tenant-a']);

        self::assertSame(1, $status);
    }

    #[Test]
    public function itDeletesATenantKey(): void
    {
        $repository = new InMemoryTenantSigningKeyRepository();
        $repository->saveManagedForTenant('tenant-a', new ManagedSigningKey('a-key', 'pub', 'priv'));

        $status = (new CommandTester(new DeleteSigningKeyCommand($repository)))
            ->execute(['--kid' => 'a-key', '--tenant' => 'tenant-a']);

        self::assertSame(0, $status);
        self::assertNull($repository->findManagedForTenant('tenant-a', 'a-key'));
    }
}

final class StubSigningKeyManager implements SigningKeyManagerInterface
{
    public function generate(string $kid, string $algorithm = 'ES256'): ManagedSigningKey
    {
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

final class InMemoryTenantSigningKeyRepository implements ManagedSigningKeyRepositoryInterface, TenantAwareManagedSigningKeyRepositoryInterface
{
    /**
     * @var array<string, array<string, ManagedSigningKey>>
     */
    public array $byTenant = [];

    public function saveManaged(ManagedSigningKey $key): void
    {
        $this->saveManagedForTenant(null, $key);
    }

    public function findManaged(string $kid): ?ManagedSigningKey
    {
        return $this->findManagedForTenant(null, $kid);
    }

    public function deleteManaged(string $kid): bool
    {
        return $this->deleteManagedForTenant(null, $kid);
    }

    public function allManaged(): array
    {
        return $this->allManagedForTenant(null);
    }

    public function active(): array
    {
        return $this->activeForTenant(null);
    }

    public function purgeRetired(string $olderThanIso8601): void
    {
    }

    public function saveManagedForTenant(?string $tenantIdentifier, ManagedSigningKey $key): void
    {
        $this->byTenant[$tenantIdentifier ?? ''][$key->kid] = $key;
    }

    public function findManagedForTenant(?string $tenantIdentifier, string $kid): ?ManagedSigningKey
    {
        return $this->byTenant[$tenantIdentifier ?? ''][$kid] ?? null;
    }

    public function deleteManagedForTenant(?string $tenantIdentifier, string $kid): bool
    {
        $tenant = $tenantIdentifier ?? '';
        if (!isset($this->byTenant[$tenant][$kid])) {
            return false;
        }
        unset($this->byTenant[$tenant][$kid]);

        return true;
    }

    public function allManagedForTenant(?string $tenantIdentifier): array
    {
        return array_values($this->byTenant[$tenantIdentifier ?? ''] ?? []);
    }

    public function activeForTenant(?string $tenantIdentifier): array
    {
        return array_values(array_filter(
            $this->byTenant[$tenantIdentifier ?? ''] ?? [],
            static fn (ManagedSigningKey $key): bool => 'active' === $key->status,
        ));
    }
}
