<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Checkout;

use Ucp\Sdk\Model\Common\Buyer;
use Ucp\Sdk\Model\Common\LineItem;

final readonly class CheckoutUpdateRequest
{
    /**
     * @param list<LineItem> $lineItems
     * @param list<DiscountCode> $discounts
     */
    public function __construct(
        public string $id,
        public array $lineItems,
        public ?Buyer $buyer = null,
        public array $discounts = [],
        public ?FulfillmentSelection $fulfillment = null,
        public ?BuyerConsent $consent = null,
        public ?PaymentInstrument $payment = null,
    ) {
    }
}
