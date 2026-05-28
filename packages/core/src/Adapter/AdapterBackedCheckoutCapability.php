<?php

declare(strict_types=1);

namespace Ucp\Sdk\Adapter;

use Ucp\Sdk\Contract\CheckoutCapabilityInterface;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Checkout\CheckoutCreateRequest;
use Ucp\Sdk\Model\Checkout\CheckoutUpdateRequest;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\RequestContext;

final readonly class AdapterBackedCheckoutCapability implements CheckoutCapabilityInterface
{
    public function __construct(
        private CapabilityDescriptor $descriptor,
        private CheckoutAdapterInterface $adapter,
    ) {
    }

    public function describe(): CapabilityDescriptor
    {
        return $this->descriptor;
    }

    public function createCheckout(CheckoutCreateRequest $request, RequestContext $context): Checkout
    {
        return $this->adapter->createCheckout($request, $context)->toCheckout();
    }

    public function getCheckout(string $id, RequestContext $context): Checkout
    {
        return $this->adapter->getCheckout($id, $context)->toCheckout();
    }

    public function updateCheckout(CheckoutUpdateRequest $request, RequestContext $context): Checkout
    {
        return $this->adapter->updateCheckout($request, $context)->toCheckout();
    }

    public function completeCheckout(string $id, RequestContext $context): Checkout
    {
        return $this->adapter->completeCheckout($id, $context)->toCheckout();
    }

    public function cancelCheckout(string $id, RequestContext $context): Checkout
    {
        return $this->adapter->cancelCheckout($id, $context)->toCheckout();
    }
}
