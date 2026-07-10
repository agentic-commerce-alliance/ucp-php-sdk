<?php

declare(strict_types=1);

namespace Ucp\Sdk\Contract;

use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Checkout\CheckoutCompleteRequest;
use Ucp\Sdk\Model\RequestContext;

/**
 * Verifies an AP2 checkout mandate against the current checkout terms before completion.
 *
 * Implementations must throw Ucp\Sdk\Exception\Ap2Exception with a stable AP2 error code
 * (for example `mandate_required`, `mandate_scope_mismatch`, `mandate_expired`) when the
 * mandate is missing, invalid, or does not cover the current checkout terms.
 *
 * Reference: https://ucp.dev/latest/specification/ap2-mandates/
 */
interface Ap2CheckoutMandateVerifierInterface
{
    public function verify(CheckoutCompleteRequest $request, Checkout $currentCheckout, RequestContext $context): void;
}
