<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Webhook;

final readonly class OrderWebhookPayload
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $event,
        public string $orderId,
        public array $payload = [],
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
