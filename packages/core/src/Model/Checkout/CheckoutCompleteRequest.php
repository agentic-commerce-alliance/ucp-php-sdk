<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Checkout;

use Ucp\Sdk\Model\Common\Signals;

/**
 * Input for the checkout complete operation.
 *
 * Complete was the only shopping operation without a request model, which is why
 * `payment` had nowhere to travel: the adapter contract took a bare id and a context,
 * so the executor validated the spec-required payment and then dropped it. This
 * mirrors CheckoutCreateRequest and CheckoutUpdateRequest so the three operations
 * finally have the same shape.
 */
final class CheckoutCompleteRequest
{
    /**
     * @param list<PaymentInstrument> $instruments
     */
    public function __construct(
        public readonly string $id,
        // A list, because payment.json models this as `{"instruments": [...]}`.
        // CheckoutCreateRequest and CheckoutUpdateRequest keep a single instrument for
        // compatibility, but read it off that same list -- preferring the one marked
        // `selected` -- instead of a top-level handler_id the spec shape does not have.
        public readonly array $instruments = [],
        public readonly ?Signals $signals = null,
    ) {
    }
}
