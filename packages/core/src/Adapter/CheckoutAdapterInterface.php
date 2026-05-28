<?php

declare(strict_types=1);

namespace Ucp\Sdk\Adapter;

use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Checkout\CheckoutCreateRequest;
use Ucp\Sdk\Model\Checkout\CheckoutUpdateRequest;
use Ucp\Sdk\Model\RequestContext;

/**
 * Optional platform-facing contract for checkout mapping.
 *
 * Projects may implement CheckoutCapabilityInterface directly instead when they do not
 * need a separate adapter layer.
 */
interface CheckoutAdapterInterface
{
    public function createCheckout(CheckoutCreateRequest $request, RequestContext $context): Checkout;

    public function getCheckout(string $id, RequestContext $context): Checkout;

    public function updateCheckout(CheckoutUpdateRequest $request, RequestContext $context): Checkout;

    public function completeCheckout(string $id, RequestContext $context): Checkout;

    public function cancelCheckout(string $id, RequestContext $context): Checkout;
}
