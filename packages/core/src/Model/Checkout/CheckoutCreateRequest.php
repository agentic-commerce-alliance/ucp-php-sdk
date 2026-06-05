<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Checkout;

use Ucp\Sdk\Model\Common\Buyer;
use Ucp\Sdk\Model\Common\LineItem;
use Ucp\Sdk\Model\Common\Signals;

final readonly class CheckoutCreateRequest
{
    /**
     * @param list<LineItem> $lineItems
     * @param list<DiscountCode> $discounts
     */
    public function __construct(
        public array $lineItems,
        public ?Buyer $buyer = null,
        public ?Signals $signals = null,
        public array $discounts = [],
        public ?FulfillmentSelection $fulfillment = null,
        public ?BuyerConsent $consent = null,
        public ?string $cartId = null,
    ) {
    }
}
