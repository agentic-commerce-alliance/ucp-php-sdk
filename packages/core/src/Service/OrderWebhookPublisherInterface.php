<?php

declare(strict_types=1);

namespace Ucp\Sdk\Service;

use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Model\Webhook\OrderWebhookPayload;
use Ucp\Sdk\Model\Webhook\WebhookDispatchResult;

interface OrderWebhookPublisherInterface
{
    public function publish(string $targetUrl, OrderWebhookPayload $payload, RequestContext $context): WebhookDispatchResult;
}
