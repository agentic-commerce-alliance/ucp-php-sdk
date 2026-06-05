<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Security;

final readonly class ManagedSigningKey
{
    public function __construct(
        public string $kid,
        public string $publicKeyPem,
        public string $privateKeyPem,
        public string $algorithm = 'ES256',
        public string $keyType = 'EC',
        public string $use = 'sig',
        public string $status = 'active',
        public ?string $curve = 'P-256',
        public ?string $createdAt = null,
        public ?string $retireAt = null,
    ) {
    }
}
