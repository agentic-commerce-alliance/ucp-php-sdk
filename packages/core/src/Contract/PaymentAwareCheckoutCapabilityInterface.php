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
 * Adding the method to CheckoutCapabilityInterface would break every implementer, so
 * this is a separate interface the executor feature-detects, the same way it already
 * resolves capabilities by `instanceof`. Implement it to receive the payment;
 * implementations that do not are called through completeCheckout() exactly as
 * before, and keep working unchanged.
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
