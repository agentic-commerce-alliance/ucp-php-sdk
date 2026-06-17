<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Internal\Service\RepositoryProfileSigningKeyProvider;
use Ucp\Sdk\Model\Profile\ProfileBuildInput;
use Ucp\Sdk\Model\Security\ManagedSigningKey;
use Ucp\Sdk\Model\Security\PublicSigningKey;
use Ucp\Sdk\Repository\ManagedSigningKeyRepositoryInterface;
use Ucp\Sdk\Repository\TenantAwareManagedSigningKeyRepositoryInterface;
use Ucp\Sdk\Service\SigningKeyManagerInterface;

final class RepositoryProfileSigningKeyProviderTest extends TestCase
{
    private SigningKeyManagerInterface $signingKeyManager;

    private int $generatedKeys = 0;

    protected function setUp(): void
    {
        $this->signingKeyManager = $this->createMock(SigningKeyManagerInterface::class);
        $this->signingKeyManager
            ->method('toPublicKey')
            ->willReturnCallback(static fn (ManagedSigningKey $key): PublicSigningKey => new PublicSigningKey($key->kid, $key->algorithm, publicKeyPem: $key->publicKeyPem));
        $this->signingKeyManager
            ->method('generate')
            ->willReturnCallback(function (string $kid, string $algorithm = 'ES256'): ManagedSigningKey {
                ++$this->generatedKeys;

                return new ManagedSigningKey($kid, 'public', 'private', $algorithm);
            });
    }

    #[Test]
    public function itReturnsExistingActiveKeys(): void
    {
        $existing = new ManagedSigningKey('existing', 'public', 'private');
        $repository = $this->createMock(ManagedSigningKeyRepositoryInterface::class);
        $repository
            ->method('active')
            ->willReturn([$existing]);
        $provider = new RepositoryProfileSigningKeyProvider(
            $repository,
            $this->signingKeyManager,
        );

        $keys = $provider->provide(new ProfileBuildInput('2026-04-08', 'https://merchant.example'));

        self::assertCount(1, $keys);
        self::assertSame('existing', $keys[0]->kid);
        self::assertSame(0, $this->generatedKeys);
    }

    #[Test]
    public function itAutoGeneratesAndStoresASigningKeyWhenConfigured(): void
    {
        /** @var ManagedSigningKey|null $saved */
        $saved = null;
        $repository = $this->createMock(ManagedSigningKeyRepositoryInterface::class);
        $repository
            ->method('active')
            ->willReturn([]);
        $repository
            ->expects($this->once())
            ->method('saveManaged')
            ->willReturnCallback(static function (ManagedSigningKey $key) use (&$saved): void {
                $saved = $key;
            });
        $provider = new RepositoryProfileSigningKeyProvider(
            $repository,
            $this->signingKeyManager,
            true,
            'generated-key',
            'ES384',
            'P30D',
        );

        $keys = $provider->provide(new ProfileBuildInput('2026-04-08', 'https://merchant.example'));

        self::assertCount(1, $keys);
        self::assertSame('generated-key', $keys[0]->kid);
        self::assertNotNull($saved);
        self::assertSame('generated-key', $saved->kid);
        self::assertSame('ES384', $saved->algorithm);
        self::assertNotNull($saved->retireAt);
        self::assertSame(1, $this->generatedKeys);
    }

    #[Test]
    public function itReadsAndStoresKeysForTheProfileTenant(): void
    {
        /** @var ManagedSigningKey|null $saved */
        $saved = null;
        $tenantIdentifier = null;
        /** @var ManagedSigningKeyRepositoryInterface&TenantAwareManagedSigningKeyRepositoryInterface&MockObject $repository */
        $repository = $this->createMockForIntersectionOfInterfaces([
            ManagedSigningKeyRepositoryInterface::class,
            TenantAwareManagedSigningKeyRepositoryInterface::class,
        ]);
        $repository
            ->method('activeForTenant')
            ->willReturnCallback(static function (?string $tenant) use (&$tenantIdentifier): array {
                $tenantIdentifier = $tenant;

                return [];
            });
        $repository
            ->expects($this->once())
            ->method('saveManagedForTenant')
            ->willReturnCallback(static function (?string $tenant, ManagedSigningKey $key) use (&$tenantIdentifier, &$saved): void {
                $tenantIdentifier = $tenant;
                $saved = $key;
            });

        $provider = new RepositoryProfileSigningKeyProvider(
            $repository,
            $this->signingKeyManager,
            true,
            'tenant-key',
        );

        $provider->provide(new ProfileBuildInput('2026-04-08', 'https://merchant.example', tenantIdentifier: 'sales-channel-a'));

        self::assertSame('sales-channel-a', $tenantIdentifier);
        self::assertNotNull($saved);
        self::assertSame('tenant-key', $saved->kid);
        self::assertSame(1, $this->generatedKeys);
    }
}
