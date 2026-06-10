<?php

declare(strict_types=1);

namespace Ucp\Sdk\Adapter;

use Ucp\Sdk\Contract\DiscountCapabilityInterface;
use Ucp\Sdk\Model\Cart\Cart;
use Ucp\Sdk\Model\Checkout\DiscountCode;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\RequestContext;

/**
 * Optional convenience wrapper for host applications that prefer small platform adapters
 * and a separate capability descriptor. Projects may implement DiscountCapabilityInterface
 * directly instead.
 */
final class AdapterBackedDiscountCapability implements DiscountCapabilityInterface
{
    public function __construct(
        private readonly CapabilityDescriptor $descriptor,
        private readonly DiscountAdapterInterface $adapter,
    ) {
    }

    public function describe(): CapabilityDescriptor
    {
        return $this->descriptor;
    }

    public function applyCartDiscount(string $cartId, DiscountCode $discount, RequestContext $context): Cart
    {
        return $this->adapter->applyCartDiscount($cartId, $discount, $context);
    }
}
