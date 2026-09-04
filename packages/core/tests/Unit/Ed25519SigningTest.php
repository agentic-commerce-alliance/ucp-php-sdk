<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Enum\SignatureAlgorithm;
use Ucp\Sdk\Exception\SignatureException;
use Ucp\Sdk\Internal\Security\ContentDigestService;
use Ucp\Sdk\Internal\Security\DefaultSigningKeyManager;
use Ucp\Sdk\Internal\Security\Ed25519KeyCodec;
use Ucp\Sdk\Internal\Security\Rfc9421RequestSignatureService;
use Ucp\Sdk\Model\Http\HttpRequest;
use Ucp\Sdk\Model\Profile\PlatformProfile;

/**
 * Ed25519 signing, which is a different shape from ECDSA rather than a variant of it.
 */
final class Ed25519SigningTest extends TestCase
{
    #[Test]
    public function itGeneratesAnOkpKeyRatherThanAnEcOne(): void
    {
        $key = (new DefaultSigningKeyManager())->generate('kid-ed', 'EdDSA');

        self::assertSame('EdDSA', $key->algorithm);
        self::assertSame('OKP', $key->keyType);
        self::assertSame('Ed25519', $key->curve);
        self::assertStringContainsString('BEGIN PUBLIC KEY', $key->publicKeyPem);
        self::assertStringContainsString('BEGIN PRIVATE KEY', $key->privateKeyPem);
    }

    #[Test]
    public function theGeneratedPemIsReadableByOpenssl(): void
    {
        // The point of storing PEM rather than sodium's raw bytes: an operator's other tools
        // have to be able to read a key this SDK wrote.
        $key = (new DefaultSigningKeyManager())->generate('kid-ed', 'EdDSA');

        self::assertNotFalse(openssl_pkey_get_public($key->publicKeyPem));
        self::assertNotFalse(openssl_pkey_get_private($key->privateKeyPem));
    }

    #[Test]
    public function itSignsAndVerifiesARequest(): void
    {
        $manager = new DefaultSigningKeyManager();
        $key = $manager->generate('kid-ed', 'EdDSA');
        $request = new HttpRequest('POST', 'https://merchant.example/ucp/v1/carts', [], [], '{"ok":true}');

        $service = new Rfc9421RequestSignatureService(new ContentDigestService());
        $headers = $service->sign($request, $key);

        $signed = new HttpRequest(
            $request->method,
            $request->absoluteUri,
            [...$request->headers, ...$headers],
            $request->query,
            $request->body,
        );

        $result = $service->verify($signed, [$manager->toPublicKey($key)]);

        self::assertTrue($result->verified, $result->failureReason ?? '');
    }

    #[Test]
    public function theSignatureIsSixtyFourBytesAndNotDer(): void
    {
        // RFC 8032 signatures are already the width the wire wants. Running one through the
        // ECDSA codec would corrupt it rather than reformat it, so it must not go there.
        $key = (new DefaultSigningKeyManager())->generate('kid-ed', 'EdDSA');
        $headers = (new Rfc9421RequestSignatureService(new ContentDigestService()))
            ->sign(new HttpRequest('GET', 'https://merchant.example/ucp/v1/orders/o1'), $key);

        $raw = base64_decode(substr($headers['Signature'], strlen('sig=:'), -1), true);

        self::assertIsString($raw);
        self::assertSame(64, strlen($raw));
        self::assertNotSame("\x30", $raw[0] ?? '', 'a DER sequence would start with 0x30');
    }

    #[Test]
    public function theWireNameIsTheRegistryIdentifier(): void
    {
        $key = (new DefaultSigningKeyManager())->generate('kid-ed', 'EdDSA');
        $headers = (new Rfc9421RequestSignatureService(new ContentDigestService()))
            ->sign(new HttpRequest('GET', 'https://merchant.example/ucp/v1/orders/o1'), $key);

        self::assertStringContainsString('alg="ed25519"', $headers['Signature-Input']);
    }

    #[Test]
    public function thePublishedJwkCarriesXAndNoY(): void
    {
        // RFC 8037: an OKP key is the public key whole in `x`. A `y` here would describe a point
        // on a curve that has no such coordinate.
        $manager = new DefaultSigningKeyManager();
        $public = $manager->toPublicKey($manager->generate('kid-ed', 'EdDSA'));

        self::assertSame('OKP', $public->keyType);
        self::assertSame('Ed25519', $public->curve);
        self::assertNotNull($public->x);
        self::assertNull($public->y);

        $decoded = base64_decode(strtr($public->x, '-_', '+/'), true);
        self::assertIsString($decoded);
        self::assertSame(32, strlen($decoded));
    }

    #[Test]
    public function aProfileWithEmptySectionsReadsBackItsOwnOutput(): void
    {
        // `toArray()` emits an empty section as `stdClass` so it encodes as `{}`, which is what
        // the schema says it is -- and `fromArray()` accepted only arrays, so a profile this
        // class produced could not be read back by this class.
        //
        // Publishing an OKP key through a profile is #151's slice, which is where
        // `PublicSigningKey` learns to tolerate key types other than EC.
        $profile = new PlatformProfile('2026-08-25', [], [], []);

        $parsed = PlatformProfile::fromArray($profile->toArray());

        self::assertSame('2026-08-25', $parsed->version);
        self::assertSame([], $parsed->services);
        self::assertSame([], $parsed->capabilities);
    }

    #[Test]
    public function aSignatureOfTheWrongLengthFailsRatherThanThrows(): void
    {
        // A peer sending a malformed signature is a failed verification, not a configuration
        // error here -- throwing would give it a different status than a merely wrong signature.
        $manager = new DefaultSigningKeyManager();
        $key = $manager->generate('kid-ed', 'EdDSA');

        $request = new HttpRequest('GET', 'https://merchant.example/ucp/v1/orders/o1', [
            'signature-input' => 'sig=("@method" "@target-uri");created=' . time() . ';expires=' . (time() + 300) . ';keyid="kid-ed";alg="ed25519"',
            'signature' => 'sig=:' . base64_encode('too-short') . ':',
        ]);

        $result = (new Rfc9421RequestSignatureService(new ContentDigestService()))
            ->verify($request, [$manager->toPublicKey($key)]);

        self::assertFalse($result->verified);
    }

    #[Test]
    public function askingAnEdwardsKeyForCoordinateWidthIsRefused(): void
    {
        // Ed25519 has no r||s pair, so the question has no answer rather than a default one.
        $this->expectException(SignatureException::class);

        SignatureAlgorithm::Ed25519->coordinateBytes();
    }

    #[Test]
    public function theCodecRoundTripsAndRejectsForeignKeys(): void
    {
        $codec = new Ed25519KeyCodec();
        $pair = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($pair);

        self::assertSame($publicKey, $codec->publicKeyFromPem($codec->publicKeyToPem($publicKey)));
        self::assertSame(
            sodium_crypto_sign_secretkey($pair),
            $codec->secretKeyFromPem($codec->secretKeyToPem(sodium_crypto_sign_secretkey($pair))),
        );

        $this->expectException(SignatureException::class);
        $ecKey = (new DefaultSigningKeyManager())->generate('kid-ec');
        $codec->publicKeyFromPem($ecKey->publicKeyPem);
    }
}
