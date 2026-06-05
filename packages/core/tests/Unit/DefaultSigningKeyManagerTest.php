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

        $publicKey = $manager->publicKeyFromJwk([
            'kid' => 'kid-jwk',
            'kty' => 'EC',
            'alg' => 'ES256',
            'use' => 'sig',
            'crv' => 'P-256',
            'x' => 'demo-x',
            'y' => 'demo-y',
        ]);

        self::assertSame('kid-jwk', $publicKey->kid);
        self::assertSame('demo-x', $publicKey->x);
        self::assertSame('demo-y', $publicKey->y);
    }
}
