<?php

declare(strict_types=1);

namespace Ucp\Sdk\Adapter;

use Ucp\Sdk\Adapter\Model\CheckoutRecord;
use Ucp\Sdk\Model\Checkout\CheckoutCreateRequest;
use Ucp\Sdk\Model\Checkout\CheckoutUpdateRequest;
use Ucp\Sdk\Model\RequestContext;

interface CheckoutAdapterInterface
{
    public function createCheckout(CheckoutCreateRequest $request, RequestContext $context): CheckoutRecord;

    public function getCheckout(string $id, RequestContext $context): CheckoutRecord;

    public function updateCheckout(CheckoutUpdateRequest $request, RequestContext $context): CheckoutRecord;

    public function completeCheckout(string $id, RequestContext $context): CheckoutRecord;

    public function cancelCheckout(string $id, RequestContext $context): CheckoutRecord;
}
