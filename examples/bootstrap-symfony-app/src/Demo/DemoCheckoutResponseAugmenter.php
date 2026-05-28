<?php

declare(strict_types=1);

namespace BootstrapSymfonyApp\Demo;

use Ucp\Sdk\Contract\CheckoutResponseAugmenterInterface;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\RequestContext;

final class DemoCheckoutResponseAugmenter implements CheckoutResponseAugmenterInterface
{
    public function augment(Checkout $checkout, RequestContext $context): Checkout
    {
        return new Checkout(
            $checkout->id,
            $checkout->status,
            $checkout->currency,
            $checkout->lineItems,
            $checkout->totals,
            $checkout->messages,
            $checkout->links,
            $checkout->buyer,
            $checkout->continueUrl,
            $checkout->expiresAt,
            $checkout->order,
            array_merge($checkout->extra, ['extension' => ['source' => 'demo']]),
        );
    }
}
