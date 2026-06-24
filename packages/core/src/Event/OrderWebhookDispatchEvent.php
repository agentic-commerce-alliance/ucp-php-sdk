<?php

declare(strict_types=1);

namespace Ucp\Sdk\Event;

use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Model\Webhook\OrderWebhookPayload;

final class OrderWebhookDispatchEvent
{
    public function __construct(
        private OrderWebhookPayload $payload,
        private readonly RequestContext $context,
        private readonly string $targetUrl,
    ) {
    }

    public function getPayload(): OrderWebhookPayload
    {
        return $this->payload;
    }

    public function replacePayload(OrderWebhookPayload $payload): void
    {
        $this->payload = $payload;
    }

    public function getContext(): RequestContext
    {
        return $this->context;
    }

    public function getTargetUrl(): string
    {
        return $this->targetUrl;
    }
}
