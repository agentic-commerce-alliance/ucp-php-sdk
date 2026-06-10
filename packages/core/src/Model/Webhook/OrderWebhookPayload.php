<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Webhook;

final class OrderWebhookPayload
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $event,
        public readonly string $orderId,
        public readonly array $payload = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge([
            'event' => $this->event,
            'order_id' => $this->orderId,
        ], $this->payload);
    }
}
