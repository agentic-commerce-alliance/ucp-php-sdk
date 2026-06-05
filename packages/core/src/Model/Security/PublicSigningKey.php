<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Security;

final readonly class PublicSigningKey
{
    /**
     * @param array<string, string> $jwk
     */
    public function __construct(
        public string $kid,
        public string $algorithm = 'ES256',
        public string $keyType = 'EC',
        public string $use = 'sig',
        public ?string $curve = null,
        public ?string $x = null,
        public ?string $y = null,
        public ?string $publicKeyPem = null,
        public array $jwk = [],
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
        return new self(
            (string) ($entry['kid'] ?? ''),
            (string) ($entry['alg'] ?? 'ES256'),
            (string) ($entry['kty'] ?? 'EC'),
            (string) ($entry['use'] ?? 'sig'),
            isset($entry['crv']) ? (string) $entry['crv'] : null,
            isset($entry['x']) ? (string) $entry['x'] : null,
            isset($entry['y']) ? (string) $entry['y'] : null,
            isset($entry['public_key_pem']) ? (string) $entry['public_key_pem'] : null,
            array_map(static fn (mixed $value): string => (string) $value, array_filter($entry, static fn (mixed $value): bool => is_scalar($value))),
        );
    }
}
