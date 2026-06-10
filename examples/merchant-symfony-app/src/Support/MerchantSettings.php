<?php

declare(strict_types=1);

namespace MerchantSymfonyApp\Support;

final class MerchantSettings
{
    public function __construct(
        public readonly string $baseUri,
        public readonly string $brandName,
        public readonly string $currency,
        public readonly string $country,
        public readonly string $defaultWebhookTarget,
    ) {
    }

    public function checkoutContinueUrl(string $checkoutId): string
    {
        return $this->baseUri . '/merchant/checkout/' . rawurlencode($checkoutId);
    }

    public function orderPermalink(string $orderId): string
    {
        return $this->baseUri . '/merchant/orders/' . rawurlencode($orderId);
    }
}
