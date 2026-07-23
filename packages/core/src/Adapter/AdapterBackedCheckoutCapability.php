<?php

declare(strict_types=1);

namespace Ucp\Sdk\Adapter;

use Ucp\Sdk\Contract\CheckoutCapabilityInterface;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Checkout\CheckoutCompleteRequest;
use Ucp\Sdk\Model\Checkout\CheckoutCreateRequest;
use Ucp\Sdk\Model\Checkout\CheckoutUpdateRequest;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\RequestContext;

/**
 * Optional convenience wrapper for host applications that prefer small platform adapters
 * and a separate capability descriptor. Projects may implement CheckoutCapabilityInterface
 * directly instead.
 */
final class AdapterBackedCheckoutCapability implements CheckoutCapabilityInterface
{
    public function __construct(
        private readonly CapabilityDescriptor $descriptor,
        private readonly CheckoutAdapterInterface $adapter,
    ) {
    }

    public function describe(): CapabilityDescriptor
    {
        return $this->descriptor;
    }

    public function createCheckout(CheckoutCreateRequest $request, RequestContext $context): Checkout
    {
        return $this->adapter->createCheckout($request, $context);
    }

    public function getCheckout(string $id, RequestContext $context): Checkout
    {
        return $this->adapter->getCheckout($id, $context);
    }

    public function updateCheckout(CheckoutUpdateRequest $request, RequestContext $context): Checkout
    {
        return $this->adapter->updateCheckout($request, $context);
    }

    public function completeCheckout(CheckoutCompleteRequest $request, RequestContext $context, ?Checkout $verifiedCheckout = null): Checkout
    {
        return $this->adapter->completeCheckout($request, $context, $verifiedCheckout);
    }

    public function cancelCheckout(string $id, RequestContext $context): Checkout
    {
        return $this->adapter->cancelCheckout($id, $context);
    }
}
