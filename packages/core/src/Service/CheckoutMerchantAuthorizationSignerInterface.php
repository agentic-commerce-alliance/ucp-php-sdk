<?php

declare(strict_types=1);

namespace Ucp\Sdk\Service;

use Ucp\Sdk\Model\RequestContext;

/**
 * Signs checkout response payloads as AP2 merchant authorizations.
 *
 * Reference: https://ucp.dev/latest/specification/ap2-mandates/
 */
interface CheckoutMerchantAuthorizationSignerInterface
{
    /**
     * @param array<string, mixed> $checkoutPayload
     */
    public function sign(array $checkoutPayload, RequestContext $context): string;
}
