<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Internal\Security\DefaultSigningKeyManager;
use Ucp\Sdk\Model\Security\ManagedSigningKey;

final class DefaultSigningKeyManagerTest extends TestCase
{
    /**
     * A real P-256 public key whose y coordinate is 31 bytes in openssl's minimal form.
     */
    private const SHORT_Y_PUBLIC_KEY_PEM = <<<'PEM'
        -----BEGIN PUBLIC KEY-----
        MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEG2g/erNlzpRUSTVQUbeInbB5o5ip
        PruxPHKnwloQ9cQAUyIiffMWPWbLh3Dh5RjXtra4VBPEilBDhIygyyKsfQ==
        -----END PUBLIC KEY-----

        PEM;

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

    /**
     * RFC 7518 section 6.2.1.2 requires each JWK coordinate to be the full width of a coordinate
     * for the curve. openssl returns them as minimal-form integers, so about one in 256 arrives a
     * byte short and used to be published that way -- which a strict JWK reader is entitled to
     * reject. The key below is a real P-256 public key whose y is 31 bytes; a generated one would
     * only exercise this on roughly one run in a hundred.
     */
    #[Test]
    public function itPublishesCoordinatesAtTheFullCurveWidthWhenOpensslReturnsAShortOne(): void
    {
        $manager = new DefaultSigningKeyManager();
        $managedKey = new ManagedSigningKey('kid-short-y', self::SHORT_Y_PUBLIC_KEY_PEM, 'unused');

        $jwk = $manager->toPublicKey($managedKey)->toJwk();

        self::assertSame('AFMiIn3zFj1my4dw4eUY17a2uFQTxIpQQ4SMoMsirH0', $jwk['y']);
        self::assertSame(32, strlen(self::base64UrlDecode($jwk['x'])));
        self::assertSame(32, strlen(self::base64UrlDecode($jwk['y'])));
    }

    #[Test]
    public function itRoundTripsAShortCoordinateBackToTheSameKey(): void
    {
        $manager = new DefaultSigningKeyManager();
        $managedKey = new ManagedSigningKey('kid-short-y', self::SHORT_Y_PUBLIC_KEY_PEM, 'unused');

        $jwk = $manager->toPublicKey($managedKey)->toJwk();

        self::assertSame(self::SHORT_Y_PUBLIC_KEY_PEM, $manager->publicKeyFromJwk($jwk)->publicKeyPem);
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

    private static function base64UrlDecode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }
}
