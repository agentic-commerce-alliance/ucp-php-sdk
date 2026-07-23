<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit\Ap2;

use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\PublicKeyLoader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Internal\Security\DefaultJsonCanonicalization;
use Ucp\Sdk\Internal\Security\DefaultSigningKeyManager;
use Ucp\Sdk\Internal\Security\DetachedJwsService;

final class DetachedJwsServiceTest extends TestCase
{
    #[Test]
    public function itSignsAndVerifiesDetachedJwsOverCanonicalCheckoutPayload(): void
    {
        $keyManager = new DefaultSigningKeyManager();
        $key = $keyManager->generate('default', 'ES256');
        $service = new DetachedJwsService(new DefaultJsonCanonicalization());
        $payload = ['id' => 'checkout-1', 'totals' => [['type' => 'total', 'amount' => 1299.0]], 'ap2' => ['ignored' => true]];

        $jws = $service->signWithoutAp2($payload, $key);

        self::assertTrue($service->verifyWithoutAp2($payload, $jws, [$keyManager->toPublicKey($key)]));
        self::assertFalse($service->verifyWithoutAp2(['id' => 'checkout-2'], $jws, [$keyManager->toPublicKey($key)]));
    }

    #[Test]
    public function itProducesCompactDetachedJwsWithAlgAndKidHeaderOnly(): void
    {
        $keyManager = new DefaultSigningKeyManager();
        $key = $keyManager->generate('merchant-key', 'ES256');
        $service = new DetachedJwsService(new DefaultJsonCanonicalization());

        $jws = $service->signWithoutAp2(['id' => 'checkout-1'], $key);

        $segments = explode('.', $jws);
        self::assertCount(3, $segments);
        self::assertSame('', $segments[1]);

        $header = json_decode(base64_decode(strtr($segments[0], '-_', '+/')), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['alg' => 'ES256', 'kid' => 'merchant-key'], $header);
    }

    #[Test]
    public function itSignsOverTheBase64UrlEncodedCanonicalPayload(): void
    {
        $keyManager = new DefaultSigningKeyManager();
        $key = $keyManager->generate('merchant-key', 'ES256');
        $canonicalizer = new DefaultJsonCanonicalization();
        $service = new DetachedJwsService($canonicalizer);
        $payload = ['id' => 'checkout-1', 'totals' => []];

        [$protected, , $encodedSignature] = explode('.', $service->signWithoutAp2($payload, $key));

        $encodedPayload = rtrim(strtr(base64_encode($canonicalizer->canonicalize($payload)), '+/', '-_'), '=');
        $publicKey = PublicKeyLoader::loadPublicKey($keyManager->toPublicKey($key)->publicKeyPem ?? '');
        self::assertInstanceOf(EC\PublicKey::class, $publicKey);
        self::assertTrue($publicKey->withSignatureFormat('IEEE')->withHash('sha256')->verify(
            $protected . '.' . $encodedPayload,
            base64_decode(strtr($encodedSignature, '-_', '+/')),
        ));
    }

    #[Test]
    public function itIgnoresTopLevelAp2DataWhenSigningAndVerifying(): void
    {
        $keyManager = new DefaultSigningKeyManager();
        $key = $keyManager->generate('default', 'ES256');
        $service = new DetachedJwsService(new DefaultJsonCanonicalization());

        $jws = $service->signWithoutAp2(['id' => 'checkout-1', 'ap2' => ['merchant_authorization' => 'a']], $key);

        self::assertTrue($service->verifyWithoutAp2(
            ['id' => 'checkout-1', 'ap2' => ['merchant_authorization' => 'something-else']],
            $jws,
            [$keyManager->toPublicKey($key)],
        ));
    }

    #[Test]
    public function itRejectsJwsWithAnAttachedPayloadSegment(): void
    {
        $keyManager = new DefaultSigningKeyManager();
        $key = $keyManager->generate('default', 'ES256');
        $service = new DetachedJwsService(new DefaultJsonCanonicalization());
        $payload = ['id' => 'checkout-1'];

        [$protected, , $signature] = explode('.', $service->signWithoutAp2($payload, $key));

        self::assertFalse($service->verifyWithoutAp2($payload, $protected . '.attached-payload.' . $signature, [$keyManager->toPublicKey($key)]));
        self::assertFalse($service->verifyWithoutAp2($payload, $protected . '.' . $signature, [$keyManager->toPublicKey($key)]));
    }

    #[Test]
    public function itRejectsValidSignaturesWhoseHeaderClaimsAnotherAlgorithm(): void
    {
        $keyManager = new DefaultSigningKeyManager();
        $key = $keyManager->generate('default', 'ES256');
        $canonicalizer = new DefaultJsonCanonicalization();
        $service = new DetachedJwsService($canonicalizer);
        $payload = ['id' => 'checkout-1'];

        $jws = $this->forgeDetachedJws(['alg' => 'ES384', 'kid' => 'default'], $payload, $key->privateKeyPem, $canonicalizer);

        self::assertFalse($service->verifyWithoutAp2($payload, $jws, [$keyManager->toPublicKey($key)]));
    }

    #[Test]
    public function itRejectsJwsWithUnencodedPayloadOrCritHeaders(): void
    {
        $keyManager = new DefaultSigningKeyManager();
        $key = $keyManager->generate('default', 'ES256');
        $canonicalizer = new DefaultJsonCanonicalization();
        $service = new DetachedJwsService($canonicalizer);
        $payload = ['id' => 'checkout-1'];

        $withB64 = $this->forgeDetachedJws(['alg' => 'ES256', 'kid' => 'default', 'b64' => false, 'crit' => ['b64']], $payload, $key->privateKeyPem, $canonicalizer);
        $withCrit = $this->forgeDetachedJws(['alg' => 'ES256', 'kid' => 'default', 'crit' => ['exp']], $payload, $key->privateKeyPem, $canonicalizer);

        self::assertFalse($service->verifyWithoutAp2($payload, $withB64, [$keyManager->toPublicKey($key)]));
        self::assertFalse($service->verifyWithoutAp2($payload, $withCrit, [$keyManager->toPublicKey($key)]));
    }

    /**
     * @param array<string, mixed> $header
     * @param array<string, mixed> $payload
     */
    private function forgeDetachedJws(array $header, array $payload, string $privateKeyPem, DefaultJsonCanonicalization $canonicalizer): string
    {
        $protected = rtrim(strtr(base64_encode(json_encode($header, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
        $encodedPayload = rtrim(strtr(base64_encode($canonicalizer->canonicalize($payload)), '+/', '-_'), '=');

        $privateKey = PublicKeyLoader::loadPrivateKey($privateKeyPem);
        self::assertInstanceOf(EC\PrivateKey::class, $privateKey);
        $signature = $privateKey->withSignatureFormat('IEEE')->withHash('sha256')->sign($protected . '.' . $encodedPayload);

        return $protected . '..' . rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }

    #[Test]
    public function itVerifiesWithTheFirstKeyMatchingTheHeaderKid(): void
    {
        $keyManager = new DefaultSigningKeyManager();
        $signingKey = $keyManager->generate('signer', 'ES256');
        $decoyKey = $keyManager->generate('decoy', 'ES256');
        $rotatedKey = $keyManager->generate('signer', 'ES256');
        $service = new DetachedJwsService(new DefaultJsonCanonicalization());
        $payload = ['id' => 'checkout-1'];

        $jws = $service->signWithoutAp2($payload, $signingKey);

        self::assertTrue($service->verifyWithoutAp2($payload, $jws, [
            $keyManager->toPublicKey($decoyKey),
            $keyManager->toPublicKey($signingKey),
            $keyManager->toPublicKey($rotatedKey),
        ]));
    }

    #[Test]
    public function itRejectsSignaturesFromUnknownKeys(): void
    {
        $keyManager = new DefaultSigningKeyManager();
        $signingKey = $keyManager->generate('default', 'ES256');
        $otherKey = $keyManager->generate('other', 'ES256');
        $service = new DetachedJwsService(new DefaultJsonCanonicalization());

        $jws = $service->signWithoutAp2(['id' => 'checkout-1'], $signingKey);

        self::assertFalse($service->verifyWithoutAp2(['id' => 'checkout-1'], $jws, [$keyManager->toPublicKey($otherKey)]));
        self::assertFalse($service->verifyWithoutAp2(['id' => 'checkout-1'], $jws, []));
    }
}
