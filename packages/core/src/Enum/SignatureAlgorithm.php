<?php

declare(strict_types=1);

namespace Ucp\Sdk\Enum;

use Ucp\Sdk\Exception\SignatureException;

/**
 * A signature algorithm, under both names it goes by.
 *
 * The case value is the JWA name (RFC 7518), which is what a JWK's `alg` member carries and
 * what this SDK stores against a managed signing key. RFC 9421 names the same algorithm
 * differently -- `ecdsa-p256-sha256`, from the HTTP Signature Algorithms registry -- and that is
 * what belongs in a `Signature-Input` header. Publishing one name where the other is expected
 * is not a formatting difference; a conformant peer rejects it.
 *
 * Both names therefore live here rather than being spelled out at each use, and storage stays
 * on the JWA name so that adding the wire name required no migration of existing keys.
 */
enum SignatureAlgorithm: string
{
    case Es256 = 'ES256';
    case Es384 = 'ES384';
    case Ed25519 = 'EdDSA';

    /**
     * The RFC 9421 HTTP Signature Algorithms registry identifier, for `Signature-Input`.
     */
    public function wireIdentifier(): string
    {
        return match ($this) {
            self::Es256 => 'ecdsa-p256-sha256',
            self::Es384 => 'ecdsa-p384-sha384',
            self::Ed25519 => 'ed25519',
        };
    }

    /**
     * Width of one coordinate on this algorithm's curve.
     *
     * RFC 9421 section 3.3.1 requires `r || s` with each half padded to exactly this, which is
     * what makes a fixed-width signature 64 bytes on P-256 and 96 on P-384.
     *
     * Ed25519 has no coordinate pair -- its signature is a single 64-byte value, not `r || s`
     * -- so asking for one is a category error rather than an unsupported case.
     *
     * @return positive-int
     */
    public function coordinateBytes(): int
    {
        return match ($this) {
            self::Es256 => 32,
            self::Es384 => 48,
            self::Ed25519 => throw new SignatureException('Ed25519 signatures are not a coordinate pair.'),
        };
    }

    /**
     * Whether openssl emits DER for this algorithm and the wire wants fixed-width halves.
     *
     * ECDSA needs the conversion; Ed25519 is already the 64 bytes RFC 8032 defines, so putting
     * it through the codec would corrupt it rather than reformat it.
     */
    public function requiresDerConversion(): bool
    {
        return $this !== self::Ed25519;
    }

    /**
     * The JWK key type: EC for the NIST curves, OKP for the Edwards one (RFC 8037).
     */
    public function keyType(): string
    {
        return $this === self::Ed25519 ? 'OKP' : 'EC';
    }

    public function curve(): string
    {
        return match ($this) {
            self::Es256 => 'P-256',
            self::Es384 => 'P-384',
            self::Ed25519 => 'Ed25519',
        };
    }

    /**
     * Resolves either name, so a peer using the registry identifier and one still using the JWA
     * name are both understood.
     */
    public static function fromIdentifier(string $identifier): self
    {
        foreach (self::cases() as $case) {
            if ($identifier === $case->value || $identifier === $case->wireIdentifier()) {
                return $case;
            }
        }

        throw new SignatureException(sprintf('Unsupported signature algorithm "%s".', $identifier));
    }

    public static function tryFromIdentifier(string $identifier): ?self
    {
        foreach (self::cases() as $case) {
            if ($identifier === $case->value || $identifier === $case->wireIdentifier()) {
                return $case;
            }
        }

        return null;
    }
}
