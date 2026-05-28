<?php

declare(strict_types=1);

namespace Ucp\Sdk\Contract;

use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\RequestContext;

interface CheckoutResponseAugmenterInterface
{
    public function augment(Checkout $checkout, RequestContext $context): Checkout;
}
