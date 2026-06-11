<?php

declare(strict_types=1);

namespace Ucp\Sdk\Adapter;

use Ucp\Sdk\Contract\CartCapabilityInterface;
use Ucp\Sdk\Model\Cart\Cart;
use Ucp\Sdk\Model\Cart\CartCreateRequest;
use Ucp\Sdk\Model\Cart\CartUpdateRequest;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\RequestContext;

/**
 * Optional convenience wrapper for host applications that prefer small platform adapters
 * and a separate capability descriptor. Projects may implement CartCapabilityInterface
 * directly instead.
 */
final class AdapterBackedCartCapability implements CartCapabilityInterface
{
    public function __construct(
        private readonly CapabilityDescriptor $descriptor,
        private readonly CartAdapterInterface $adapter,
    ) {
    }

    public function describe(): CapabilityDescriptor
    {
        return $this->descriptor;
    }

    public function createCart(CartCreateRequest $request, RequestContext $context): Cart
    {
        return $this->adapter->createCart($request, $context);
    }

    public function getCart(string $id, RequestContext $context): Cart
    {
        return $this->adapter->getCart($id, $context);
    }

    public function updateCart(CartUpdateRequest $request, RequestContext $context): Cart
    {
        return $this->adapter->updateCart($request, $context);
    }

    public function cancelCart(string $id, RequestContext $context): Cart
    {
        return $this->adapter->cancelCart($id, $context);
    }
}
