<?php

declare(strict_types=1);

namespace MerchantSymfonyApp\Ucp;

use MerchantSymfonyApp\Support\MerchantSettings;
use Ucp\Sdk\Contract\CheckoutResponseAugmenterInterface;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\RequestContext;

final class MerchantCheckoutResponseAugmenter implements CheckoutResponseAugmenterInterface
{
    public function __construct(
        private readonly MerchantSettings $settings,
    ) {
    }

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
            array_merge($checkout->extra, [
                'merchant' => [
                    'brand' => $this->settings->brandName,
                    'country' => $this->settings->country,
                    'signature_verified' => $context->signatureVerified,
                ],
            ]),
        );
    }
}
