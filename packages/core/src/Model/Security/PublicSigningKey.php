<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Security;

final class PublicSigningKey
{
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
        $curve = isset($entry['crv']) ? (string) $entry['crv'] : null;
        $x = isset($entry['x']) ? (string) $entry['x'] : null;
        $y = isset($entry['y']) ? (string) $entry['y'] : null;
        $publicKeyPem = isset($entry['public_key_pem'])
            ? (string) $entry['public_key_pem']
            : self::publicKeyPemFromEcJwk($curve, $x, $y);

        return new self(
            (string) ($entry['kid'] ?? ''),
            (string) ($entry['alg'] ?? 'ES256'),
            (string) ($entry['kty'] ?? 'EC'),
            (string) ($entry['use'] ?? 'sig'),
            $curve,
            $x,
            $y,
            $publicKeyPem,
            array_map(static fn (mixed $value): string => (string) $value, array_filter($entry, static fn (mixed $value): bool => is_scalar($value))),
        );
    }

    private static function publicKeyPemFromEcJwk(?string $curve, ?string $x, ?string $y): ?string
    {
        $curveOid = match ($curve) {
            'P-256' => '1.2.840.10045.3.1.7',
            'P-384' => '1.3.132.0.34',
            default => null,
        };

        if ($curveOid === null || $x === null || $y === null || $x === '' || $y === '') {
            return null;
        }

        $xBytes = self::base64UrlDecode($x);
        $yBytes = self::base64UrlDecode($y);
        if ($xBytes === null || $yBytes === null) {
            return null;
        }

        $algorithmIdentifier = self::derSequence(
            self::derOid('1.2.840.10045.2.1') . self::derOid($curveOid),
        );
        $subjectPublicKeyInfo = self::derSequence(
            $algorithmIdentifier . self::derBitString("\x04" . $xBytes . $yBytes),
        );
        $pem = "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($subjectPublicKeyInfo), 64, "\n")
            . "-----END PUBLIC KEY-----\n";

        return openssl_pkey_get_public($pem) === false ? null : $pem;
    }

    private static function base64UrlDecode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);

        return $decoded === false ? null : $decoded;
    }

    private static function derSequence(string $value): string
    {
        return "\x30" . self::derLength(strlen($value)) . $value;
    }

    private static function derBitString(string $value): string
    {
        return "\x03" . self::derLength(strlen($value) + 1) . "\x00" . $value;
    }

    private static function derOid(string $oid): string
    {
        $parts = array_map('intval', explode('.', $oid));
        $encoded = chr(($parts[0] * 40) + $parts[1]);

        foreach (array_slice($parts, 2) as $part) {
            $stack = [chr($part & 0x7f)];
            $part >>= 7;

            while ($part > 0) {
                array_unshift($stack, chr(($part & 0x7f) | 0x80));
                $part >>= 7;
            }

            $encoded .= implode('', $stack);
        }

        return "\x06" . self::derLength(strlen($encoded)) . $encoded;
    }

    private static function derLength(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }

        $encoded = '';
        while ($length > 0) {
            $encoded = chr($length & 0xff) . $encoded;
            $length >>= 8;
        }

        return chr(0x80 | strlen($encoded)) . $encoded;
    }
}
