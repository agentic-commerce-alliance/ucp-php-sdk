<?php

declare(strict_types=1);

namespace Ucp\Sdk\Contract;

use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Checkout\CheckoutCompleteRequest;
use Ucp\Sdk\Model\RequestContext;

/**
 * Verifies an AP2 checkout mandate against the current checkout terms before completion.
 *
 * A missing mandate is rejected with `mandate_required` before verifiers run when AP2 is
 * active for the request. Implementations must throw Ucp\Sdk\Exception\Ap2Exception with a
 * stable AP2 error code (for example `mandate_invalid_signature`, `mandate_scope_mismatch`,
 * `mandate_expired`) when the mandate is invalid or does not cover the current checkout terms.
 *
 * Reference: https://ucp.dev/latest/specification/ap2-mandates/
 */
interface Ap2CheckoutMandateVerifierInterface
{
    public function verify(CheckoutCompleteRequest $request, Checkout $currentCheckout, RequestContext $context): void;
}
