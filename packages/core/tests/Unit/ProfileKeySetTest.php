<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Internal\Security\DefaultSigningKeyManager;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Model\Security\PublicSigningKey;

/**
 * A profile's key set: published as `keys`, and read without letting one key sink the rest.
 *
 * The spec is explicit that the `kty`, `crv` and `alg` vocabularies are open — a verifier
 * tolerates values it does not recognise, selects by `kid`, and lets an unsupported key affect
 * only the signature that references it. This SDK did the opposite: it required `alg`, `use`
 * and `crv`, accepted only `kty=EC`, and threw. `PlatformProfile` called that in a loop with no
 * `try`, so one Ed25519 key made the whole profile unparseable — and a platform publishing one
 * is doing exactly what the spec recommends for Web Bot Auth interop.
 */
final class ProfileKeySetTest extends TestCase
{
    #[Test]
    public function itPublishesTheKeySetAsKeys(): void
    {
        $profile = new PlatformProfile('2026-08-25', [], [], [], [$this->ecKey('kid-published')]);

        $payload = $profile->toArray();

        self::assertArrayHasKey('keys', $payload);
        self::assertArrayNotHasKey('signing_keys', $payload);
        self::assertSame('kid-published', $payload['keys'][0]['kid']);
    }

    #[Test]
    public function itReadsTheKeySetFromKeys(): void
    {
        $profile = PlatformProfile::fromArray([
            'ucp' => ['version' => '2026-08-25', 'services' => [], 'capabilities' => [], 'payment_handlers' => []],
            'keys' => [$this->ecKey('kid-read')->toJwk()],
        ]);

        self::assertCount(1, $profile->signingKeys);
        self::assertSame('kid-read', $profile->signingKeys[0]->kid);
    }

    /**
     * For one release, so a peer that has not moved to `keys` yet still parses.
     */
    #[Test]
    public function itStillReadsTheLegacySigningKeysField(): void
    {
        $profile = PlatformProfile::fromArray([
            'ucp' => ['version' => '2026-04-08', 'services' => [], 'capabilities' => [], 'payment_handlers' => []],
            'signing_keys' => [$this->ecKey('kid-legacy')->toJwk()],
        ]);

        self::assertCount(1, $profile->signingKeys);
        self::assertSame('kid-legacy', $profile->signingKeys[0]->kid);
    }

    /**
     * The case that used to cost the whole profile.
     */
    #[Test]
    public function anUnsupportedKeyTypeDoesNotSinkTheKeysBesideIt(): void
    {
        $profile = PlatformProfile::fromArray([
            'ucp' => ['version' => '2026-08-25', 'services' => [], 'capabilities' => [], 'payment_handlers' => []],
            'keys' => [
                // OKP/Ed25519 is what the spec recommends for Web Bot Auth interop. This SDK
                // cannot verify with it yet, so it is skipped rather than fatal.
                ['kid' => 'kid-ed25519', 'kty' => 'OKP', 'crv' => 'Ed25519', 'alg' => 'EdDSA', 'x' => 'ZmFrZS1lZDI1NTE5LXB1YmxpYy1rZXk'],
                $this->ecKey('kid-ec')->toJwk(),
            ],
        ]);

        self::assertCount(1, $profile->signingKeys, 'the EC key survives');
        self::assertSame('kid-ec', $profile->signingKeys[0]->kid);
    }

    #[Test]
    public function aProfileOfOnlyUnsupportedKeysParsesWithNoUsableKeys(): void
    {
        $profile = PlatformProfile::fromArray([
            'ucp' => ['version' => '2026-08-25', 'services' => [], 'capabilities' => [], 'payment_handlers' => []],
            'keys' => [
                ['kid' => 'kid-future', 'kty' => 'ML-DSA', 'crv' => 'ML-DSA-44', 'x' => 'c29tZXRoaW5nLW5ldw'],
            ],
        ]);

        self::assertSame([], $profile->signingKeys);
    }

    /**
     * Not "unsupported" — malformed, and dangerous. `profile.json` forbids private members
     * outright, so this is either a leaked secret or an attempt to have us treat one as a
     * verification key. Rejected rather than skipped.
     */
    #[Test]
    public function itRefusesAKeyCarryingPrivateMaterial(): void
    {
        $jwk = $this->ecKey('kid-private')->toJwk();
        $jwk['d'] = 'cHJpdmF0ZS1zY2FsYXI';

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('carries the private JWK member "d"');

        PublicSigningKey::tryFromJwk($jwk);
    }

    /**
     * `profile.json` requires only `kid` and `kty`; a verifier derives the algorithm from the
     * curve when `alg` is absent. Requiring it rejected conformant keys.
     */
    #[Test]
    public function itDerivesTheAlgorithmFromTheCurveWhenAlgIsAbsent(): void
    {
        $jwk = $this->ecKey('kid-no-alg')->toJwk();
        unset($jwk['alg'], $jwk['use']);

        $key = PublicSigningKey::tryFromJwk($jwk);

        self::assertNotNull($key);
        self::assertSame('ES256', $key->algorithm);
        self::assertSame('sig', $key->use);
    }

    private function ecKey(string $kid): PublicSigningKey
    {
        $manager = new DefaultSigningKeyManager();

        return $manager->toPublicKey($manager->generate($kid));
    }
}
