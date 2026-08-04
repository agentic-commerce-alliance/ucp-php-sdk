<?php

declare(strict_types=1);

namespace Ucp\Sdk\Adapter;

use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Checkout\CheckoutCompleteRequest;
use Ucp\Sdk\Model\RequestContext;

/**
 * Opt-in extension for checkout adapters that act on the completion payment.
 *
 * The adapter-layer counterpart of PaymentAwareCheckoutCapabilityInterface, for
 * platforms that use AdapterBackedCheckoutCapability rather than implementing the
 * capability directly. AdapterBackedCheckoutCapability forwards to this when the
 * adapter implements it and falls back to completeCheckout() when it does not, so
 * adopting it is a one-method change with no coordination.
 */
interface PaymentAwareCheckoutAdapterInterface extends CheckoutAdapterInterface
{
    public function completeCheckoutFromRequest(CheckoutCompleteRequest $request, RequestContext $context): Checkout;
}
