<?php

declare(strict_types=1);

namespace Ucp\Sdk\Adapter;

use Ucp\Sdk\Adapter\Model\CartRecord;
use Ucp\Sdk\Model\Checkout\DiscountCode;
use Ucp\Sdk\Model\RequestContext;

interface DiscountAdapterInterface
{
    public function applyCartDiscount(string $cartId, DiscountCode $discount, RequestContext $context): CartRecord;
}
