<?php

declare(strict_types=1);

namespace Ucp\Sdk\Adapter;

use Ucp\Sdk\Contract\CartCapabilityInterface;
use Ucp\Sdk\Model\Cart\Cart;
use Ucp\Sdk\Model\Cart\CartCreateRequest;
use Ucp\Sdk\Model\Cart\CartUpdateRequest;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\RequestContext;

final readonly class AdapterBackedCartCapability implements CartCapabilityInterface
{
    public function __construct(
        private CapabilityDescriptor $descriptor,
        private CartAdapterInterface $adapter,
    ) {
    }

    public function describe(): CapabilityDescriptor
    {
        return $this->descriptor;
    }

    public function createCart(CartCreateRequest $request, RequestContext $context): Cart
    {
        return $this->adapter->createCart($request, $context)->toCart();
    }

    public function getCart(string $id, RequestContext $context): Cart
    {
        return $this->adapter->getCart($id, $context)->toCart();
    }

    public function updateCart(CartUpdateRequest $request, RequestContext $context): Cart
    {
        return $this->adapter->updateCart($request, $context)->toCart();
    }

    public function cancelCart(string $id, RequestContext $context): Cart
    {
        return $this->adapter->cancelCart($id, $context)->toCart();
    }
}
