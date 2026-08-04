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
        // CheckoutUpdateRequest::$payment flattens it to a single instrument and reads
        // a top-level handler_id, which silently turns a spec-shaped payload into an
        // instrument with an empty handler id. Not repeating that here.
        public readonly array $instruments = [],
        public readonly ?Signals $signals = null,
    ) {
    }
}
