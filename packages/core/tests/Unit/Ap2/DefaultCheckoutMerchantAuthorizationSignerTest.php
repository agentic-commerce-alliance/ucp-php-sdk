<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit\Ap2;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Exception\SignatureException;
use Ucp\Sdk\Internal\Security\DefaultCheckoutMerchantAuthorizationSigner;
use Ucp\Sdk\Internal\Security\DefaultJsonCanonicalization;
use Ucp\Sdk\Internal\Security\DefaultSigningKeyManager;
use Ucp\Sdk\Internal\Security\DetachedJwsService;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Model\Security\ManagedSigningKey;
use Ucp\Sdk\Repository\ManagedSigningKeyRepositoryInterface;

final class DefaultCheckoutMerchantAuthorizationSignerTest extends TestCase
{
    #[Test]
    public function itSignsCheckoutPayloadsWithTheActiveManagedKey(): void
    {
        $keyManager = new DefaultSigningKeyManager();
        $key = $keyManager->generate('merchant-key', 'ES256');
        $detachedJws = new DetachedJwsService(new DefaultJsonCanonicalization());
        $signer = new DefaultCheckoutMerchantAuthorizationSigner(new Ap2SignerKeyRepositoryFake([$key]), $detachedJws);

        $payload = ['id' => 'checkout-1', 'totals' => []];
        $jws = $signer->sign($payload, new RequestContext('shop.example'));

        self::assertTrue($detachedJws->verifyWithoutAp2($payload, $jws, [$keyManager->toPublicKey($key)]));
    }

    #[Test]
    public function itSkipsActiveKeysWithUnsupportedAlgorithms(): void
    {
        $keyManager = new DefaultSigningKeyManager();
        $es384Key = $keyManager->generate('merchant-key-es384', 'ES384');
        $es256Key = $keyManager->generate('merchant-key-es256', 'ES256');
        $detachedJws = new DetachedJwsService(new DefaultJsonCanonicalization());
        $signer = new DefaultCheckoutMerchantAuthorizationSigner(new Ap2SignerKeyRepositoryFake([$es384Key, $es256Key]), $detachedJws);

        $payload = ['id' => 'checkout-1', 'totals' => []];
        $jws = $signer->sign($payload, new RequestContext('shop.example'));

        self::assertTrue($detachedJws->verifyWithoutAp2($payload, $jws, [$keyManager->toPublicKey($es256Key)]));
    }

    #[Test]
    public function itUsesTheNewestActiveEs256KeyRegardlessOfRepositoryOrder(): void
    {
        $keyManager = new DefaultSigningKeyManager();
        $olderKey = self::withCreatedAt($keyManager->generate('merchant-key-older', 'ES256'), '2026-07-01T00:00:00+00:00');
        $newerKey = self::withCreatedAt($keyManager->generate('merchant-key-newer', 'ES256'), '2026-07-15T00:00:00+00:00');
        $detachedJws = new DetachedJwsService(new DefaultJsonCanonicalization());
        $payload = ['id' => 'checkout-1', 'totals' => []];

        foreach ([[$olderKey, $newerKey], [$newerKey, $olderKey]] as $repositoryOrder) {
            $signer = new DefaultCheckoutMerchantAuthorizationSigner(new Ap2SignerKeyRepositoryFake($repositoryOrder), $detachedJws);
            $jws = $signer->sign($payload, new RequestContext('shop.example'));

            self::assertTrue($detachedJws->verifyWithoutAp2($payload, $jws, [$keyManager->toPublicKey($newerKey)]));
            self::assertFalse($detachedJws->verifyWithoutAp2($payload, $jws, [$keyManager->toPublicKey($olderKey)]));
        }
    }

    #[Test]
    public function itBreaksCreatedAtTiesByKidAndSortsKeysWithoutCreatedAtLast(): void
    {
        $keyManager = new DefaultSigningKeyManager();
        $keyA = self::withCreatedAt($keyManager->generate('merchant-key-a', 'ES256'), '2026-07-01T00:00:00+00:00');
        $keyB = self::withCreatedAt($keyManager->generate('merchant-key-b', 'ES256'), '2026-07-01T00:00:00+00:00');
        $undatedKey = self::withCreatedAt($keyManager->generate('merchant-key-0-undated', 'ES256'), null);
        $detachedJws = new DetachedJwsService(new DefaultJsonCanonicalization());
        $payload = ['id' => 'checkout-1', 'totals' => []];

        $signer = new DefaultCheckoutMerchantAuthorizationSigner(new Ap2SignerKeyRepositoryFake([$undatedKey, $keyB, $keyA]), $detachedJws);
        $jws = $signer->sign($payload, new RequestContext('shop.example'));

        self::assertTrue($detachedJws->verifyWithoutAp2($payload, $jws, [$keyManager->toPublicKey($keyA)]));
        self::assertFalse($detachedJws->verifyWithoutAp2($payload, $jws, [$keyManager->toPublicKey($keyB)]));
        self::assertFalse($detachedJws->verifyWithoutAp2($payload, $jws, [$keyManager->toPublicKey($undatedKey)]));
    }

    private static function withCreatedAt(ManagedSigningKey $key, ?string $createdAt): ManagedSigningKey
    {
        return new ManagedSigningKey(
            $key->kid,
            $key->publicKeyPem,
            $key->privateKeyPem,
            $key->algorithm,
            $key->keyType,
            $key->use,
            $key->status,
            $key->curve,
            $createdAt,
            $key->retireAt,
        );
    }

    #[Test]
    public function itFailsWhenOnlyNonEs256KeysAreActive(): void
    {
        $keyManager = new DefaultSigningKeyManager();
        $signer = new DefaultCheckoutMerchantAuthorizationSigner(
            new Ap2SignerKeyRepositoryFake([$keyManager->generate('merchant-key-es384', 'ES384')]),
            new DetachedJwsService(new DefaultJsonCanonicalization()),
        );

        $this->expectException(SignatureException::class);

        $signer->sign(['id' => 'checkout-1'], new RequestContext('shop.example'));
    }

    #[Test]
    public function itFailsWithoutAnActiveSigningKey(): void
    {
        $signer = new DefaultCheckoutMerchantAuthorizationSigner(
            new Ap2SignerKeyRepositoryFake([]),
            new DetachedJwsService(new DefaultJsonCanonicalization()),
        );

        $this->expectException(SignatureException::class);

        $signer->sign(['id' => 'checkout-1'], new RequestContext('shop.example'));
    }
}

final class Ap2SignerKeyRepositoryFake implements ManagedSigningKeyRepositoryInterface
{
    /**
     * @param list<ManagedSigningKey> $keys
     */
    public function __construct(private array $keys)
    {
    }

    public function saveManaged(ManagedSigningKey $key): void
    {
        $this->keys[] = $key;
    }

    public function findManaged(string $kid): ?ManagedSigningKey
    {
        foreach ($this->keys as $key) {
            if ($key->kid === $kid) {
                return $key;
            }
        }

        return null;
    }

    public function deleteManaged(string $kid): bool
    {
        return false;
    }

    public function allManaged(): array
    {
        return $this->keys;
    }

    public function active(): array
    {
        return $this->keys;
    }

    public function purgeRetired(string $olderThanIso8601): void
    {
    }
}
