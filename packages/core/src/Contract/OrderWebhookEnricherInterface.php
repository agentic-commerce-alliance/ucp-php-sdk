<?php

declare(strict_types=1);

namespace Ucp\Sdk\Contract;

use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Model\Webhook\OrderWebhookPayload;

interface OrderWebhookEnricherInterface
{
    public function enrich(OrderWebhookPayload $payload, RequestContext $context): OrderWebhookPayload;
}
