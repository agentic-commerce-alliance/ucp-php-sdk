<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Enum\AdjustmentStatus;
use Ucp\Sdk\Model\Common\Money;
use Ucp\Sdk\Model\Order\Adjustment;
use Ucp\Sdk\Model\Order\AdjustmentLineItem;
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

    #[Test]
    public function itSerializesOrderAdjustments(): void
    {
        $order = new OrderView(
            'order-1',
            'EUR',
            [],
            [],
            adjustments: [new Adjustment(
                'adjustment-1',
                'cancellation',
                '2026-07-14T10:30:00+00:00',
                AdjustmentStatus::Completed,
                [new AdjustmentLineItem('line-item-1', -1)],
                [new Money('total', -10.0)],
                'Cancelled by the merchant.',
            )],
        );

        self::assertSame([[
            'id' => 'adjustment-1',
            'type' => 'cancellation',
            'occurred_at' => '2026-07-14T10:30:00+00:00',
            'status' => 'completed',
            'line_items' => [[
                'id' => 'line-item-1',
                'quantity' => -1,
            ]],
            'totals' => [[
                'type' => 'total',
                'amount' => -1000,
            ]],
            'description' => 'Cancelled by the merchant.',
        ]], $order->toArray()['adjustments'] ?? null);
    }
}
