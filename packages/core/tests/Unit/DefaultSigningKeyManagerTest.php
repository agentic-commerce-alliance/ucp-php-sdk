<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Internal\Security\DefaultSigningKeyManager;

final class DefaultSigningKeyManagerTest extends TestCase
{
    #[Test]
    public function itGeneratesManagedKeysAndProjectsPublicJwks(): void
    {
        $manager = new DefaultSigningKeyManager();

        $managedKey = $manager->generate('kid-1');
        $publicKey = $manager->toPublicKey($managedKey);

        self::assertSame('kid-1', $managedKey->kid);
        self::assertSame('ES256', $managedKey->algorithm);
        self::assertNotEmpty($managedKey->privateKeyPem);
        self::assertSame('kid-1', $publicKey->kid);
        self::assertSame('P-256', $publicKey->curve);
        self::assertNotEmpty($publicKey->x);
        self::assertNotEmpty($publicKey->y);
    }

    #[Test]
    public function itCreatesPublicKeysFromJwks(): void
    {
        $manager = new DefaultSigningKeyManager();
        $jwk = $manager->toPublicKey($manager->generate('kid-jwk'))->toJwk();

        $publicKey = $manager->publicKeyFromJwk($jwk);

        self::assertSame('kid-jwk', $publicKey->kid);
        self::assertSame($jwk['x'], $publicKey->x);
        self::assertSame($jwk['y'], $publicKey->y);
    }

    #[Test]
    public function itCreatesEs384PublicKeysFromJwks(): void
    {
        $manager = new DefaultSigningKeyManager();
        $jwk = $manager->toPublicKey($manager->generate('kid-jwk-es384', 'ES384'))->toJwk();

        $publicKey = $manager->publicKeyFromJwk($jwk);

        self::assertSame('kid-jwk-es384', $publicKey->kid);
        self::assertSame('ES384', $publicKey->algorithm);
        self::assertSame('P-384', $publicKey->curve);
        self::assertSame($jwk['x'], $publicKey->x);
        self::assertSame($jwk['y'], $publicKey->y);
    }
}
