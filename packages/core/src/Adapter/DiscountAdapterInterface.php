<?php

declare(strict_types=1);

namespace Ucp\Sdk\Adapter;

use Ucp\Sdk\Model\Cart\Cart;
use Ucp\Sdk\Model\Checkout\DiscountCode;
use Ucp\Sdk\Model\RequestContext;

/**
 * Optional platform-facing contract for discount application.
 *
 * Projects may implement DiscountCapabilityInterface directly instead when they do not
 * need a separate adapter layer.
 */
interface DiscountAdapterInterface
{
    public function applyCartDiscount(string $cartId, DiscountCode $discount, RequestContext $context): Cart;
}
