<?php

declare(strict_types=1);

namespace Ucp\Sdk\Internal\Security;

use Ucp\Sdk\Exception\SignatureException;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Repository\ManagedSigningKeyRepositoryInterface;
use Ucp\Sdk\Repository\TenantAwareManagedSigningKeyRepositoryInterface;
use Ucp\Sdk\Service\CheckoutMerchantAuthorizationSignerInterface;

/** @internal */
final class DefaultCheckoutMerchantAuthorizationSigner implements CheckoutMerchantAuthorizationSignerInterface
{
    public function __construct(
        private readonly ManagedSigningKeyRepositoryInterface $signingKeyRepository,
        private readonly DetachedJwsService $detachedJwsService,
    ) {
    }

    public function sign(array $checkoutPayload, RequestContext $context): string
    {
        $keys = $this->signingKeyRepository instanceof TenantAwareManagedSigningKeyRepositoryInterface
            ? $this->signingKeyRepository->activeForTenant($context->runtimeConfiguration?->tenantIdentifier)
            : $this->signingKeyRepository->active();

        $key = null;
        foreach ($keys as $candidate) {
            // DetachedJwsService emits ES256-only JWS; other active keys (e.g. ES384) must not be used.
            if ($candidate->algorithm === 'ES256') {
                $key = $candidate;
                break;
            }
        }

        if ($key === null) {
            throw new SignatureException('No active ES256 signing key is available for AP2 merchant authorizations.');
        }

        return $this->detachedJwsService->signWithoutAp2($checkoutPayload, $key);
    }
}
