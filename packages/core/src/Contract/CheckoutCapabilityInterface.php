<?php

declare(strict_types=1);

namespace Ucp\Sdk\Contract;

use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Checkout\CheckoutCreateRequest;
use Ucp\Sdk\Model\Checkout\CheckoutUpdateRequest;
use Ucp\Sdk\Model\RequestContext;

/**
 * Checkout session operations.
 *
 * Reference: https://ucp.dev/specification/checkout/
 */
interface CheckoutCapabilityInterface extends CapabilityInterface
{
    public function createCheckout(CheckoutCreateRequest $request, RequestContext $context): Checkout;

    public function getCheckout(string $id, RequestContext $context): Checkout;

    public function updateCheckout(CheckoutUpdateRequest $request, RequestContext $context): Checkout;

    public function completeCheckout(string $id, RequestContext $context): Checkout;

    public function cancelCheckout(string $id, RequestContext $context): Checkout;
}
