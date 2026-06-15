<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Internal\Security\DefaultSigningKeyManager;
use Ucp\Sdk\Internal\Security\EcJwkPublicKeyConverter;

final class EcJwkPublicKeyConverterTest extends TestCase
{
    #[Test]
    public function itConvertsGeneratedEcJwksToOpenSslPublicKeyPem(): void
    {
        $manager = new DefaultSigningKeyManager();
        $jwk = $manager->toPublicKey($manager->generate('kid-converter'))->toJwk();

        $pem = EcJwkPublicKeyConverter::toPem($jwk['crv'] ?? null, $jwk['x'] ?? null, $jwk['y'] ?? null);

        self::assertIsString($pem);
        self::assertStringStartsWith('-----BEGIN PUBLIC KEY-----', $pem);
        self::assertNotFalse(openssl_pkey_get_public($pem));
    }

    #[Test]
    public function itReturnsNullForUnsupportedOrMalformedJwks(): void
    {
        self::assertNull(EcJwkPublicKeyConverter::toPem('P-521', 'x', 'y'));
        self::assertNull(EcJwkPublicKeyConverter::toPem('P-256', 'not valid base64url!', 'y'));
        self::assertNull(EcJwkPublicKeyConverter::toPem('P-256', null, 'y'));
    }
}
