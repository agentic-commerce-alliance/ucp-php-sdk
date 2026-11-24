<?php

declare(strict_types=1);

namespace Ucp\Sdk\Contract;

use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Checkout\CheckoutCompleteRequest;
use Ucp\Sdk\Model\RequestContext;

/**
 * Opt-in extension for checkout capabilities that act on the completion payment.
 *
 * UCP marks `payment` as required for the complete operation -- checkout.json
 * annotates it `ucp_request: {complete: "required"}` -- but
 * CheckoutCapabilityInterface::completeCheckout() takes only an id and a context, so
 * the executor validated the payment and then discarded it. Every UCP business was
 * therefore obliged to collect a field nothing could read.
 *
 * Unless `dev.ucp.common.payment.split_payments` is negotiated, the executor refuses a
 * completion without exactly one instrument before either method is called.
 *
 * @see PaymentAwareCheckoutAdapterInterface for the adapter-layer equivalent
 */
interface PaymentAwareCheckoutCapabilityInterface extends CheckoutCapabilityInterface
{
    /**
     * Completes a checkout with the full request, payment included.
     *
     * Deliberately not named completeCheckout(): a different signature under the same
     * name would make the two interfaces impossible to implement together, which is
     * what forces the breaking change this interface exists to avoid.
     */
    public function completeCheckoutFromRequest(CheckoutCompleteRequest $request, RequestContext $context): Checkout;
}
