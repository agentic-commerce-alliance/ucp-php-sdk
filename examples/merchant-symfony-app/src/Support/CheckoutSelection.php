<?php

declare(strict_types=1);

namespace MerchantSymfonyApp\Support;

use Ucp\Sdk\Model\Checkout\DiscountCode;
use Ucp\Sdk\Model\Checkout\FulfillmentSelection;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\Common\Buyer;
use Ucp\Sdk\Model\Common\LineItem;

/**
 * What the buyer has chosen so far, as a create or update request carries it. The checkout is
 * priced from this; the response echoes it back.
 */
final class CheckoutSelection
{
    /**
     * @param list<LineItem>     $lineItems
     * @param list<DiscountCode> $discounts
     */
    public function __construct(
        public readonly array $lineItems,
        public readonly array $discounts,
        public readonly ?FulfillmentSelection $fulfillment,
        public readonly ?Buyer $buyer,
        public readonly ?PaymentInstrument $payment,
    ) {
    }
}
