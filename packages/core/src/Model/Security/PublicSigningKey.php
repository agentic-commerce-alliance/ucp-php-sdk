<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Security;

use Ucp\Sdk\Internal\Security\EcJwkPublicKeyConverter;

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
            : EcJwkPublicKeyConverter::toPem($curve, $x, $y);

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
}
