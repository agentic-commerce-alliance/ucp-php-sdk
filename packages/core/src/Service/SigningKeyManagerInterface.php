<?php

declare(strict_types=1);

namespace Ucp\Sdk\Service;

use Ucp\Sdk\Model\Security\ManagedSigningKey;
use Ucp\Sdk\Model\Security\PublicSigningKey;

interface SigningKeyManagerInterface
{
    public function generate(string $kid, string $algorithm = 'ES256'): ManagedSigningKey;

    public function toPublicKey(ManagedSigningKey $key): PublicSigningKey;

    /**
     * @param array<string, string> $jwk
     */
    public function publicKeyFromJwk(array $jwk): PublicSigningKey;
}
