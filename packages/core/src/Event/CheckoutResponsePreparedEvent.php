<?php

declare(strict_types=1);

namespace Ucp\Sdk\Event;

use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\RequestContext;

final class CheckoutResponsePreparedEvent
{
    public function __construct(
        private Checkout $checkout,
        private readonly RequestContext $context,
    ) {
    }

    public function getCheckout(): Checkout
    {
        return $this->checkout;
    }

    public function replaceCheckout(Checkout $checkout): void
    {
        $this->checkout = $checkout;
    }

    public function getContext(): RequestContext
    {
        return $this->context;
    }
}
