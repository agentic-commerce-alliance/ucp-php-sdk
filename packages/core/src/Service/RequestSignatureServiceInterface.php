<?php

declare(strict_types=1);

namespace Ucp\Sdk\Service;

use Ucp\Sdk\Model\Http\HttpRequest;
use Ucp\Sdk\Model\Security\ManagedSigningKey;
use Ucp\Sdk\Model\Security\PublicSigningKey;
use Ucp\Sdk\Model\Security\SignatureVerificationResult;

interface RequestSignatureServiceInterface
{
    /**
     * @return array<string, string>
     */
    public function sign(HttpRequest $request, ManagedSigningKey $key, ?int $created = null, ?int $expires = null): array;

    /**
     * @param list<PublicSigningKey> $keys
     */
    public function verify(HttpRequest $request, array $keys): SignatureVerificationResult;
}
