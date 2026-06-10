<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Checkout;

use Ucp\Sdk\Model\Common\Buyer;
use Ucp\Sdk\Model\Common\LineItem;
use Ucp\Sdk\Model\Common\Signals;

final class CheckoutCreateRequest
{
    /**
     * @param list<LineItem> $lineItems
     * @param list<DiscountCode> $discounts
     */
    public function __construct(
        public readonly array $lineItems,
        public readonly ?Buyer $buyer = null,
        public readonly ?Signals $signals = null,
        public readonly array $discounts = [],
        public readonly ?FulfillmentSelection $fulfillment = null,
        public readonly ?BuyerConsent $consent = null,
        public readonly ?string $cartId = null,
    ) {
    }
}
