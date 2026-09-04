<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Security;

use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Internal\Security\EcPublicKeyPem;
use Ucp\Sdk\Internal\Security\Ed25519KeyCodec;

final class PublicSigningKey
{
    private const SUPPORTED_ALGORITHM_CURVES = [
        'ES256' => 'P-256',
        'ES384' => 'P-384',
        'EdDSA' => 'Ed25519',
    ];

    /**
     * Key types this SDK can verify with, and the curves that belong to each.
     *
     * An Edwards key is `OKP`, not `EC`, and RFC 8037 gives it no `y` -- the public key is
     * carried whole in `x`. Checking `kty` against a single expected value was correct while EC
     * was the only thing that could be verified with, and became a rejection of a key type this
     * SDK now signs with itself.
     */
    private const KEY_TYPE_CURVES = [
        'EC' => ['P-256', 'P-384'],
        'OKP' => ['Ed25519'],
    ];

    /**
     * JWK members that only ever appear on a *private* key.
     *
     * A profile publishes public keys; `profile.json` forbids these outright. A key carrying one
     * is either a mistake that leaked a secret into a public document or an attempt to have us
     * treat one as a verification key, and neither should be quietly accepted.
     *
     * @var list<string>
     */
    private const PRIVATE_JWK_MEMBERS = ['d', 'p', 'q', 'dp', 'dq', 'qi', 'oth', 'k'];

    /**
     * @param array<string, string> $jwk
     */
    public function __construct(
        public readonly string $kid,
        public readonly string $algorithm = 'ES256',
        public readonly string $keyType = 'EC',
        public readonly string $use = 'sig',
        public readonly ?string $curve = null,
        public readonly ?string $x = null,
        public readonly ?string $y = null,
        public readonly ?string $publicKeyPem = null,
        public readonly array $jwk = [],
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function toJwk(): array
    {
        if ($this->jwk !== []) {
            return $this->jwk;
        }

        return array_filter([
            'kid' => $this->kid,
            'kty' => $this->keyType,
            'alg' => $this->algorithm,
            'use' => $this->use,
            'crv' => $this->curve,
            'x' => $this->x,
            'y' => $this->y,
        ], static fn (?string $value): bool => $value !== null && $value !== '');
    }

    /**
     * Parses a JWK, or returns null when it is one this SDK cannot verify with.
     *
     * The spec is explicit that the `kty`, `crv` and `alg` vocabularies are **open**: a verifier
     * must tolerate values it does not recognise, select keys by `kid`, and let an unsupported
     * key affect only the signature that references it. It must not reject the whole profile.
     *
     * `fromJwk()` throws, and `PlatformProfile` called it in a loop with no `try`, so a single
     * Ed25519 key in an otherwise usable profile made the entire profile unparseable -- and a
     * platform publishing one is doing exactly what the spec recommends for Web Bot Auth
     * interop. Callers reading a remote profile want this method; callers validating their own
     * input want `fromJwk()`.
     *
     * A key carrying private material is still rejected outright rather than skipped: that is
     * malformed input, not an unsupported algorithm.
     *
     * @param array<string, mixed> $entry
     */
    public static function tryFromJwk(array $entry): ?self
    {
        self::assertNoPrivateMaterial($entry);

        try {
            return self::fromJwk($entry);
        } catch (ValidationException) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $entry
     */
    public static function fromJwk(array $entry): self
    {
        self::assertNoPrivateMaterial($entry);

        $kid = self::requiredString($entry, 'kid');
        $keyType = self::requiredString($entry, 'kty', $kid);
        $curve = self::requiredString($entry, 'crv', $kid);

        // `alg` and `use` are optional in `profile.json`; only `kid` and `kty` are required, and
        // a verifier derives the algorithm from the curve when `alg` is absent. Requiring them
        // rejected conformant keys.
        $algorithm = self::optionalString($entry, 'alg') ?? self::algorithmForCurve($kid, $curve);
        $use = self::optionalString($entry, 'use') ?? 'sig';

        $expectedCurve = self::expectedCurve($kid, $algorithm);
        self::assertKeyTypeCarriesCurve($kid, $keyType, $curve);
        self::assertSupported($kid, 'use', $use, 'sig');
        self::assertSupported($kid, 'crv', $curve, $expectedCurve);

        $x = self::optionalString($entry, 'x');
        $y = self::optionalString($entry, 'y');
        $publicKeyPem = self::optionalString($entry, 'public_key_pem');

        // An OKP key has no `y`, so demanding one would reject every conformant Ed25519 key.
        $needsY = $keyType !== 'OKP';
        if ($publicKeyPem === null && ($x === null || ($needsY && $y === null))) {
            throw new ValidationException(sprintf(
                'Public signing key "%s" must include either public_key_pem or %s.',
                $kid,
                $needsY ? 'x and y coordinates' : 'an x coordinate',
            ));
        }

        $publicKeyPem = $publicKeyPem !== null
            ? self::normalizePublicKeyPem($publicKeyPem, $kid)
            : self::normalizeJwkPublicKeyPem($curve, $x, $y, $kid);

        return new self(
            $kid,
            $algorithm,
            $keyType,
            $use,
            $curve,
            $x,
            $y,
            $publicKeyPem,
            array_map(static fn (mixed $value): string => (string) $value, array_filter($entry, static fn (mixed $value): bool => is_scalar($value))),
        );
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function requiredString(array $entry, string $field, ?string $kid = null): string
    {
        $value = $entry[$field] ?? null;
        if (! is_string($value) || trim($value) === '') {
            $subject = $kid === null ? 'Public signing key' : sprintf('Public signing key "%s"', $kid);

            throw new ValidationException(sprintf('%s "%s" must be a non-empty string.', $subject, $field));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function optionalString(array $entry, string $field): ?string
    {
        $value = $entry[$field] ?? null;
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || trim($value) === '') {
            throw new ValidationException(sprintf('Public signing key field "%s" must be a non-empty string when present.', $field));
        }

        return $value;
    }

    /**
     * The pairing is what matters: an `EC` key on `Ed25519`, or an `OKP` key on `P-256`,
     * describes a point on a curve that key type does not use.
     */
    private static function assertKeyTypeCarriesCurve(string $kid, string $keyType, string $curve): void
    {
        $curves = self::KEY_TYPE_CURVES[$keyType] ?? null;

        if ($curves === null) {
            throw new ValidationException(sprintf('Public signing key "%s" uses unsupported kty "%s".', $kid, $keyType));
        }

        if (in_array($curve, $curves, true)) {
            return;
        }

        // Two different mistakes, and telling them apart is the difference between "this SDK
        // cannot verify with that curve" and "that curve does not belong to that key type".
        $everySupported = array_merge(...array_values(self::KEY_TYPE_CURVES));
        if (! in_array($curve, $everySupported, true)) {
            throw new ValidationException(sprintf('Public signing key "%s" uses unsupported crv "%s".', $kid, $curve));
        }

        throw new ValidationException(sprintf(
            'Public signing key "%s" pairs kty "%s" with crv "%s", which do not belong together.',
            $kid,
            $keyType,
            $curve,
        ));
    }

    private static function assertSupported(string $kid, string $field, string $actual, string $expected): void
    {
        if ($actual !== $expected) {
            throw new ValidationException(sprintf('Public signing key "%s" uses unsupported %s "%s".', $kid, $field, $actual));
        }
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function assertNoPrivateMaterial(array $entry): void
    {
        foreach (self::PRIVATE_JWK_MEMBERS as $member) {
            if (array_key_exists($member, $entry)) {
                throw new ValidationException(sprintf(
                    'Public signing key carries the private JWK member "%s"; a profile publishes public keys only.',
                    $member,
                ));
            }
        }
    }

    private static function algorithmForCurve(string $kid, string $curve): string
    {
        $algorithm = array_search($curve, self::SUPPORTED_ALGORITHM_CURVES, true);
        if (! is_string($algorithm)) {
            throw new ValidationException(sprintf('Public signing key "%s" uses unsupported crv "%s".', $kid, $curve));
        }

        return $algorithm;
    }

    private static function expectedCurve(string $kid, string $algorithm): string
    {
        if (! array_key_exists($algorithm, self::SUPPORTED_ALGORITHM_CURVES)) {
            throw new ValidationException(sprintf('Public signing key "%s" uses unsupported alg "%s".', $kid, $algorithm));
        }

        return self::SUPPORTED_ALGORITHM_CURVES[$algorithm];
    }

    private static function normalizePublicKeyPem(string $publicKeyPem, string $kid): string
    {
        try {
            return EcPublicKeyPem::normalize($publicKeyPem);
        } catch (\Throwable) {
            throw new ValidationException(sprintf('Public signing key "%s" contains unusable key material.', $kid));
        }
    }

    private static function normalizeJwkPublicKeyPem(string $curve, string $x, ?string $y, string $kid): string
    {
        try {
            // An Edwards key is the 32 bytes in `x` and nothing else, so there are no
            // coordinates to assemble -- only a fixed DER header to put in front of them.
            if ($curve === 'Ed25519') {
                return (new Ed25519KeyCodec())->publicKeyToPem(self::decodeBase64Url($x, $kid));
            }

            if ($y === null) {
                throw new ValidationException(sprintf('Public signing key "%s" is missing its y coordinate.', $kid));
            }

            return EcPublicKeyPem::fromCoordinates($curve, $x, $y);
        } catch (\Throwable) {
            throw new ValidationException(sprintf('Public signing key "%s" contains unusable key material.', $kid));
        }
    }

    private static function decodeBase64Url(string $value, string $kid): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        if ($decoded === false) {
            throw new ValidationException(sprintf('Public signing key "%s" has a malformed x coordinate.', $kid));
        }

        return $decoded;
    }
}
