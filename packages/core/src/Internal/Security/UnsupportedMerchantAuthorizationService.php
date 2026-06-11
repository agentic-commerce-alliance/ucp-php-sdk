<?php

declare(strict_types=1);

namespace Ucp\Sdk\Internal\Security;

use Ucp\Sdk\Model\Http\HttpRequest;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Model\Security\MerchantAuthorizationVerificationResult;
use Ucp\Sdk\Service\MerchantAuthorizationServiceInterface;

/** @internal */
final class UnsupportedMerchantAuthorizationService implements MerchantAuthorizationServiceInterface
{
    public function verify(HttpRequest $request, array $keys, RequestContext $context): MerchantAuthorizationVerificationResult
    {
        $header = $request->headers['x-ucp-merchant-authorization'] ?? $request->headers['X-Ucp-Merchant-Authorization'] ?? null;
        if ($header === null || $header === '') {
            return new MerchantAuthorizationVerificationResult(false, failureReason: 'Merchant authorization header is missing.');
        }

        return new MerchantAuthorizationVerificationResult(
            false,
            failureReason: 'Merchant authorization verification is not implemented by the shared SDK. Provide a platform-specific service if this flow is required.',
        );
    }
}
