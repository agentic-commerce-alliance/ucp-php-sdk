<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Model\Order\OrderView;

final class OrderViewTest extends TestCase
{
    #[Test]
    public function itModelsSpecOrderFieldsWithoutExtraPayload(): void
    {
        $order = new OrderView(
            'order-1',
            'EUR',
            [],
            [],
            checkoutId: 'checkout-1',
            permalinkUrl: 'https://merchant.example/orders/order-1',
            fulfillment: [],
        );

        $payload = $order->toArray();

        self::assertSame('checkout-1', $payload['checkout_id'] ?? null);
        self::assertSame('https://merchant.example/orders/order-1', $payload['permalink_url'] ?? null);
        self::assertSame([], $payload['fulfillment'] ?? null);
        self::assertArrayNotHasKey('extra', $payload);
    }
}
