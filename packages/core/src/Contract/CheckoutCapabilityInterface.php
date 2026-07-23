<?php

declare(strict_types=1);

namespace Ucp\Sdk\Contract;

use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Checkout\CheckoutCompleteRequest;
use Ucp\Sdk\Model\Checkout\CheckoutCreateRequest;
use Ucp\Sdk\Model\Checkout\CheckoutUpdateRequest;
use Ucp\Sdk\Model\RequestContext;

/**
 * Checkout session operations.
 *
 * Reference: https://ucp.dev/specification/checkout/
 */
interface CheckoutCapabilityInterface extends CapabilityInterface
{
    public function createCheckout(CheckoutCreateRequest $request, RequestContext $context): Checkout;

    public function getCheckout(string $id, RequestContext $context): Checkout;

    public function updateCheckout(CheckoutUpdateRequest $request, RequestContext $context): Checkout;

    /**
     * When $verifiedCheckout is provided, it is the checkout snapshot that AP2 mandate
     * verifiers approved for this completion. Implementations MUST NOT complete the checkout
     * if its terms (line items, totals, currency) no longer match this snapshot — e.g. because
     * a concurrent update landed after verification — and should throw
     * Ucp\Sdk\Exception\Ap2Exception with the stable code `mandate_scope_mismatch` instead.
     *
     * The read-guard-write MUST be atomic: comparing the stored terms against the snapshot and
     * then writing the completed checkout in two separate steps still lets a concurrent update
     * slip in between them. Use a lock or a compare-and-set on the terms (see
     * {@see Checkout::termsFingerprint()} and the merchant example's
     * JsonStateStore::mutate() for the reference pattern).
     */
    public function completeCheckout(CheckoutCompleteRequest $request, RequestContext $context, ?Checkout $verifiedCheckout = null): Checkout;

    public function cancelCheckout(string $id, RequestContext $context): Checkout;
}
