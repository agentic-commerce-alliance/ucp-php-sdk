<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit\Ap2;

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
    public function itProducesCompactDetachedJwsWithUnencodedPayloadHeader(): void
    {
        $keyManager = new DefaultSigningKeyManager();
        $key = $keyManager->generate('merchant-key', 'ES256');
        $service = new DetachedJwsService(new DefaultJsonCanonicalization());

        $jws = $service->signWithoutAp2(['id' => 'checkout-1'], $key);

        $segments = explode('.', $jws);
        self::assertCount(3, $segments);
        self::assertSame('', $segments[1]);

        $header = json_decode(base64_decode(strtr($segments[0], '-_', '+/')), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ES256', $header['alg']);
        self::assertSame('merchant-key', $header['kid']);
        self::assertFalse($header['b64']);
        self::assertSame(['b64'], $header['crit']);
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
