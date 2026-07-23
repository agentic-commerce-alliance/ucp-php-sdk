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
 * Verifiers are combined with OR semantics: the executor runs every verifier that
 * {@see self::supports()} the request, and the completion proceeds only if at least one of
 * them accepts the mandate. If no registered verifier supports the request the completion
 * fails closed (`mandate_format_unsupported`), so a mandate is never accepted unverified.
 *
 * Reference: https://ucp.dev/latest/specification/ap2-mandates/
 */
interface Ap2CheckoutMandateVerifierInterface
{
    /**
     * Whether this verifier can validate the mandate carried by the request (e.g. it handles
     * the presentation format the business advertised in `vp_formats_supported`).
     */
    public function supports(CheckoutCompleteRequest $request, Checkout $currentCheckout, RequestContext $context): bool;

    public function verify(CheckoutCompleteRequest $request, Checkout $currentCheckout, RequestContext $context): void;
}
