<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Security;

use phpseclib3\Crypt\PublicKeyLoader;
use Ucp\Sdk\Exception\ValidationException;

final class PublicSigningKey
{
    private const SUPPORTED_ALGORITHM_CURVES = [
        'ES256' => 'P-256',
        'ES384' => 'P-384',
    ];

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
     * @param array<string, mixed> $entry
     */
    public static function fromJwk(array $entry): self
    {
        $kid = self::requiredString($entry, 'kid');
        $algorithm = self::requiredString($entry, 'alg', $kid);
        $keyType = self::requiredString($entry, 'kty', $kid);
        $use = self::requiredString($entry, 'use', $kid);
        $curve = self::requiredString($entry, 'crv', $kid);

        $expectedCurve = self::expectedCurve($kid, $algorithm);
        self::assertSupported($kid, 'kty', $keyType, 'EC');
        self::assertSupported($kid, 'use', $use, 'sig');
        self::assertSupported($kid, 'crv', $curve, $expectedCurve);

        $x = self::optionalString($entry, 'x');
        $y = self::optionalString($entry, 'y');
        $publicKeyPem = self::optionalString($entry, 'public_key_pem');

        if ($publicKeyPem === null && ($x === null || $y === null)) {
            throw new ValidationException(sprintf('Public signing key "%s" must include either public_key_pem or x and y coordinates.', $kid));
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

    private static function assertSupported(string $kid, string $field, string $actual, string $expected): void
    {
        if ($actual !== $expected) {
            throw new ValidationException(sprintf('Public signing key "%s" uses unsupported %s "%s".', $kid, $field, $actual));
        }
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
            return PublicKeyLoader::loadPublicKey($publicKeyPem)->toString('PKCS8');
        } catch (\Throwable) {
            throw new ValidationException(sprintf('Public signing key "%s" contains unusable key material.', $kid));
        }
    }

    private static function normalizeJwkPublicKeyPem(string $curve, string $x, string $y, string $kid): string
    {
        try {
            $jwk = json_encode([
                'kty' => 'EC',
                'crv' => $curve,
                'x' => $x,
                'y' => $y,
            ], JSON_THROW_ON_ERROR);

            return PublicKeyLoader::loadPublicKey($jwk)->toString('PKCS8');
        } catch (\Throwable) {
            throw new ValidationException(sprintf('Public signing key "%s" contains unusable key material.', $kid));
        }
    }
}
