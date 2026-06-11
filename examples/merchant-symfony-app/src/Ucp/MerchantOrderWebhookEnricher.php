<?php

declare(strict_types=1);

namespace MerchantSymfonyApp\Ucp;

use MerchantSymfonyApp\Support\MerchantSettings;
use Ucp\Sdk\Contract\OrderWebhookEnricherInterface;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Model\Webhook\OrderWebhookPayload;

final class MerchantOrderWebhookEnricher implements OrderWebhookEnricherInterface
{
    public function __construct(
        private readonly MerchantSettings $settings,
    ) {
    }

    public function enrich(OrderWebhookPayload $payload, RequestContext $context): OrderWebhookPayload
    {
        return new OrderWebhookPayload(
            $payload->event,
            $payload->orderId,
            array_merge($payload->payload, [
                'merchant' => [
                    'brand' => $this->settings->brandName,
                    'country' => $this->settings->country,
                ],
            ]),
        );
    }
}
