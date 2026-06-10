<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Checkout;

use Ucp\Sdk\Model\Common\Buyer;
use Ucp\Sdk\Model\Common\LineItem;

final class CheckoutUpdateRequest
{
    /**
     * @param list<LineItem> $lineItems
     * @param list<DiscountCode> $discounts
     */
    public function __construct(
        public readonly string $id,
        public readonly array $lineItems,
        public readonly ?Buyer $buyer = null,
        public readonly array $discounts = [],
        public readonly ?FulfillmentSelection $fulfillment = null,
        public readonly ?BuyerConsent $consent = null,
        public readonly ?PaymentInstrument $payment = null,
    ) {
    }
}
