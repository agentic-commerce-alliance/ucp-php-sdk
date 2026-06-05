<?php

declare(strict_types=1);

namespace Ucp\Sdk\Contract;

use Ucp\Sdk\Model\Cart\Cart;
use Ucp\Sdk\Model\Checkout\DiscountCode;
use Ucp\Sdk\Model\RequestContext;

/**
 * Discount application operations for shopping flows.
 *
 * Reference: https://ucp.dev/specification/overview/
 */
interface DiscountCapabilityInterface extends CapabilityInterface
{
    public function applyCartDiscount(string $cartId, DiscountCode $discount, RequestContext $context): Cart;
}
