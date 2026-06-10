<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Security;

final class ManagedSigningKey
{
    public function __construct(
        public readonly string $kid,
        public readonly string $publicKeyPem,
        public readonly string $privateKeyPem,
        public readonly string $algorithm = 'ES256',
        public readonly string $keyType = 'EC',
        public readonly string $use = 'sig',
        public readonly string $status = 'active',
        public readonly ?string $curve = 'P-256',
        public readonly ?string $createdAt = null,
        public readonly ?string $retireAt = null,
    ) {
    }
}
