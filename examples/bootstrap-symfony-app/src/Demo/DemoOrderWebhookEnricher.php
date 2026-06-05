<?php

declare(strict_types=1);

namespace BootstrapSymfonyApp\Demo;

use Ucp\Sdk\Contract\OrderWebhookEnricherInterface;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Model\Webhook\OrderWebhookPayload;

final class DemoOrderWebhookEnricher implements OrderWebhookEnricherInterface
{
    public function enrich(OrderWebhookPayload $payload, RequestContext $context): OrderWebhookPayload
    {
        return new OrderWebhookPayload($payload->event, $payload->orderId, array_merge($payload->payload, ['enriched' => true]));
    }
}
