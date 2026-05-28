<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Internal\Service\RepositoryProfileSigningKeyProvider;
use Ucp\Sdk\Model\Profile\ProfileBuildInput;
use Ucp\Sdk\Model\Security\ManagedSigningKey;
use Ucp\Sdk\Model\Security\PublicSigningKey;
use Ucp\Sdk\Repository\ManagedSigningKeyRepositoryInterface;
use Ucp\Sdk\Service\SigningKeyManagerInterface;

final class RepositoryProfileSigningKeyProviderTest extends TestCase
{
    #[Test]
    public function itReturnsExistingActiveKeys(): void
    {
        $existing = new ManagedSigningKey('existing', 'public', 'private');
        $provider = new RepositoryProfileSigningKeyProvider(
            new class ($existing) implements ManagedSigningKeyRepositoryInterface {
                public function __construct(private readonly ManagedSigningKey $existing)
                {
                }

                public function saveManaged(ManagedSigningKey $key): void
                {
                    throw new \RuntimeException('saveManaged should not be called when active keys already exist.');
                }

                public function findManaged(string $kid): ?ManagedSigningKey
                {
                    return null;
                }

                public function allManaged(): array
                {
                    return [$this->existing];
                }

                public function active(): array
                {
                    return [$this->existing];
                }

                public function purgeRetired(string $olderThanIso8601): void
                {
                }
            },
            new class () implements SigningKeyManagerInterface {
                public function generate(string $kid, string $algorithm = 'ES256'): ManagedSigningKey
                {
                    throw new \RuntimeException('generate should not be called when active keys already exist.');
                }

                public function toPublicKey(ManagedSigningKey $key): PublicSigningKey
                {
                    return new PublicSigningKey($key->kid, publicKeyPem: $key->publicKeyPem);
                }

                public function publicKeyFromJwk(array $jwk): PublicSigningKey
                {
                    throw new \RuntimeException('Not used in this test.');
                }
            },
        );

        $keys = $provider->provide(new ProfileBuildInput('2026-04-08', 'https://merchant.example'));

        self::assertCount(1, $keys);
        self::assertSame('existing', $keys[0]->kid);
    }

    #[Test]
    public function itAutoGeneratesAndStoresASigningKeyWhenConfigured(): void
    {
        $state = new RepositoryProfileSigningKeyProviderState();
        $provider = new RepositoryProfileSigningKeyProvider(
            new class ($state) implements ManagedSigningKeyRepositoryInterface {
                public function __construct(private readonly RepositoryProfileSigningKeyProviderState $state)
                {
                }

                public function saveManaged(ManagedSigningKey $key): void
                {
                    $this->state->saved = $key;
                }

                public function findManaged(string $kid): ?ManagedSigningKey
                {
                    return null;
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
            new class () implements SigningKeyManagerInterface {
                public function generate(string $kid, string $algorithm = 'ES256'): ManagedSigningKey
                {
                    return new ManagedSigningKey($kid, 'public', 'private', $algorithm);
                }

                public function toPublicKey(ManagedSigningKey $key): PublicSigningKey
                {
                    return new PublicSigningKey($key->kid, $key->algorithm, publicKeyPem: $key->publicKeyPem);
                }

                public function publicKeyFromJwk(array $jwk): PublicSigningKey
                {
                    throw new \RuntimeException('Not used in this test.');
                }
            },
            true,
            'generated-key',
            'ES384',
            'P30D',
        );

        $keys = $provider->provide(new ProfileBuildInput('2026-04-08', 'https://merchant.example'));

        self::assertCount(1, $keys);
        self::assertSame('generated-key', $keys[0]->kid);
        self::assertNotNull($state->saved);
        self::assertSame('generated-key', $state->saved->kid);
        self::assertSame('ES384', $state->saved->algorithm);
        self::assertNotNull($state->saved->retireAt);
    }
}

final class RepositoryProfileSigningKeyProviderState
{
    public ?ManagedSigningKey $saved = null;
}
