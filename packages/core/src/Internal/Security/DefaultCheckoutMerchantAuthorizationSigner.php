<?php

declare(strict_types=1);

namespace Ucp\Sdk\Internal\Security;

use Ucp\Sdk\Exception\SignatureException;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Model\Security\ManagedSigningKey;
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

        // DetachedJwsService emits ES256-only JWS; other active keys (e.g. ES384) must not be used.
        $candidates = array_values(array_filter($keys, static fn (ManagedSigningKey $key): bool => $key->algorithm === 'ES256'));

        if ($candidates === []) {
            throw new SignatureException('No active ES256 signing key is available for AP2 merchant authorizations.');
        }

        // The repository does not guarantee an order, so pick deterministically: the newest key
        // (ISO 8601 createdAt sorts lexicographically) wins, keys without createdAt sort last,
        // and kid breaks ties. This keeps the signing key predictable during rotations.
        usort($candidates, static function (ManagedSigningKey $a, ManagedSigningKey $b): int {
            return ($b->createdAt ?? '') <=> ($a->createdAt ?? '') ?: $a->kid <=> $b->kid;
        });

        return $this->detachedJwsService->signWithoutAp2($checkoutPayload, $candidates[0]);
    }
}
