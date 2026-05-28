<?php

declare(strict_types=1);

namespace MerchantSymfonyApp\Support;

final readonly class MerchantSettings
{
    public function __construct(
        public string $baseUri,
        public string $brandName,
        public string $currency,
        public string $country,
        public string $defaultWebhookTarget,
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
