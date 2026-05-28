<?php

declare(strict_types=1);

namespace Ucp\Sdk\Service;

use Ucp\Sdk\Model\Http\HttpRequest;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Model\Security\MerchantAuthorizationVerificationResult;
use Ucp\Sdk\Model\Security\PublicSigningKey;

interface MerchantAuthorizationServiceInterface
{
    /**
     * @param list<PublicSigningKey> $keys
     */
    public function verify(HttpRequest $request, array $keys, RequestContext $context): MerchantAuthorizationVerificationResult;
}
