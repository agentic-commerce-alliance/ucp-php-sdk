<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Internal\Security\DefaultSigningKeyManager;
use Ucp\Sdk\Model\Security\PublicSigningKey;

final class PublicSigningKeyTest extends TestCase
{
    #[Test]
    public function itParsesSupportedEcSigningKeys(): void
    {
        $manager = new DefaultSigningKeyManager();
        $jwk = $manager->toPublicKey($manager->generate('kid-1'))->toJwk();

        $key = PublicSigningKey::fromJwk($jwk);

        self::assertSame('kid-1', $key->kid);
        self::assertSame('ES256', $key->algorithm);
        self::assertSame('EC', $key->keyType);
        self::assertSame('sig', $key->use);
        self::assertSame('P-256', $key->curve);
        self::assertSame($jwk['x'], $key->x);
        self::assertSame($jwk['y'], $key->y);
    }

    #[Test]
    public function itParsesSupportedEs384SigningKeys(): void
    {
        $manager = new DefaultSigningKeyManager();
        $jwk = $manager->toPublicKey($manager->generate('kid-es384', 'ES384'))->toJwk();

        $key = PublicSigningKey::fromJwk($jwk);

        self::assertSame('kid-es384', $key->kid);
        self::assertSame('ES384', $key->algorithm);
        self::assertSame('P-384', $key->curve);
        self::assertSame($jwk['x'], $key->x);
        self::assertSame($jwk['y'], $key->y);
    }

    /**
     * @param array<string, mixed> $jwk
     */
    #[DataProvider('invalidJwks')]
    #[Test]
    public function itRejectsInvalidPublicSigningKeys(array $jwk, string $message): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($message);

        PublicSigningKey::fromJwk($jwk);
    }

    #[DataProvider('supportedCurves')]
    #[Test]
    public function itNormalizesJwkCoordinatesToTheCanonicalOpensslPem(string $algorithm, string $curve, string $curveName): void
    {
        $manager = new DefaultSigningKeyManager();
        $managed = $manager->generate('kid-1', $algorithm);
        $jwk = $manager->toPublicKey($managed)->toJwk();

        $key = PublicSigningKey::fromJwk($jwk);

        self::assertSame($curve, $key->curve);
        self::assertSame($managed->publicKeyPem, $key->publicKeyPem);
    }

    #[DataProvider('supportedCurves')]
    #[Test]
    public function itLeavesAnOpensslPublicKeyPemUnchanged(string $algorithm, string $curve, string $curveName): void
    {
        $manager = new DefaultSigningKeyManager();
        $managed = $manager->generate('kid-1', $algorithm);

        $key = PublicSigningKey::fromJwk([
            'kid' => 'kid-1',
            'kty' => 'EC',
            'alg' => $algorithm,
            'use' => 'sig',
            'crv' => $curve,
            'public_key_pem' => $managed->publicKeyPem,
        ]);

        self::assertSame($managed->publicKeyPem, $key->publicKeyPem);
    }

    #[DataProvider('supportedCurves')]
    #[Test]
    public function itProducesAPemOpensslLoadsBackToTheInputCoordinates(string $algorithm, string $curve, string $curveName): void
    {
        $manager = new DefaultSigningKeyManager();
        $jwk = $manager->toPublicKey($manager->generate('kid-1', $algorithm))->toJwk();

        $key = PublicSigningKey::fromJwk($jwk);

        self::assertIsString($key->publicKeyPem);
        self::assertStringStartsWith("-----BEGIN PUBLIC KEY-----\n", $key->publicKeyPem);

        $resource = openssl_pkey_get_public($key->publicKeyPem);
        self::assertNotFalse($resource);

        $details = openssl_pkey_get_details($resource);
        self::assertIsArray($details);
        self::assertIsArray($details['ec']);
        self::assertSame($curveName, $details['ec']['curve_name']);
        self::assertIsString($details['ec']['x']);
        self::assertIsString($details['ec']['y']);
        self::assertSame($jwk['x'], self::base64UrlEncode($details['ec']['x']));
        self::assertSame($jwk['y'], self::base64UrlEncode($details['ec']['y']));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function supportedCurves(): iterable
    {
        yield 'ES256' => ['ES256', 'P-256', 'prime256v1'];
        yield 'ES384' => ['ES384', 'P-384', 'secp384r1'];
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidJwks(): iterable
    {
        yield 'missing kid' => [
            [
                'kty' => 'EC',
                'alg' => 'ES256',
                'use' => 'sig',
                'crv' => 'P-256',
                'x' => 'abc',
                'y' => 'def',
            ],
            'Public signing key "kid" must be a non-empty string.',
        ];

        yield 'unsupported algorithm' => [
            [
                'kid' => 'kid-1',
                'kty' => 'EC',
                'alg' => 'none',
                'use' => 'sig',
                'crv' => 'P-256',
                'x' => 'abc',
                'y' => 'def',
            ],
            'Public signing key "kid-1" uses unsupported alg "none".',
        ];

        yield 'unsupported key type' => [
            [
                'kid' => 'kid-1',
                'kty' => 'oct',
                'alg' => 'ES256',
                'use' => 'sig',
                'crv' => 'P-256',
                'x' => 'abc',
                'y' => 'def',
            ],
            'Public signing key "kid-1" uses unsupported kty "oct".',
        ];

        yield 'unsupported curve' => [
            [
                'kid' => 'kid-1',
                'kty' => 'EC',
                'alg' => 'ES256',
                'use' => 'sig',
                'crv' => 'P-521',
                'x' => 'abc',
                'y' => 'def',
            ],
            'Public signing key "kid-1" uses unsupported crv "P-521".',
        ];

        yield 'curve incompatible with algorithm' => [
            [
                'kid' => 'kid-1',
                'kty' => 'EC',
                'alg' => 'ES384',
                'use' => 'sig',
                'crv' => 'P-256',
                'x' => 'abc',
                'y' => 'def',
            ],
            'Public signing key "kid-1" uses unsupported crv "P-256".',
        ];

        yield 'missing key material' => [
            [
                'kid' => 'kid-1',
                'kty' => 'EC',
                'alg' => 'ES256',
                'use' => 'sig',
                'crv' => 'P-256',
            ],
            'Public signing key "kid-1" must include either public_key_pem or x and y coordinates.',
        ];

        yield 'unusable key material' => [
            [
                'kid' => 'kid-1',
                'kty' => 'EC',
                'alg' => 'ES256',
                'use' => 'sig',
                'crv' => 'P-256',
                'public_key_pem' => 'not-a-public-key',
            ],
            'Public signing key "kid-1" contains unusable key material.',
        ];

        yield 'coordinates that are not base64url' => [
            [
                'kid' => 'kid-1',
                'kty' => 'EC',
                'alg' => 'ES256',
                'use' => 'sig',
                'crv' => 'P-256',
                'x' => 'not base64url!!',
                'y' => 'not base64url!!',
            ],
            'Public signing key "kid-1" contains unusable key material.',
        ];

        yield 'coordinates sized for another curve' => [
            [
                'kid' => 'kid-1',
                'kty' => 'EC',
                'alg' => 'ES256',
                'use' => 'sig',
                'crv' => 'P-256',
                'x' => self::base64UrlEncode(str_repeat('a', 48)),
                'y' => self::base64UrlEncode(str_repeat('b', 48)),
            ],
            'Public signing key "kid-1" contains unusable key material.',
        ];

        yield 'coordinates that are not a point on the curve' => [
            [
                'kid' => 'kid-1',
                'kty' => 'EC',
                'alg' => 'ES256',
                'use' => 'sig',
                'crv' => 'P-256',
                'x' => self::base64UrlEncode(str_repeat("\x11", 32)),
                'y' => self::base64UrlEncode(str_repeat("\x22", 32)),
            ],
            'Public signing key "kid-1" contains unusable key material.',
        ];
    }
}
