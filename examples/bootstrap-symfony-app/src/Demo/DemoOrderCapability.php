<?php

declare(strict_types=1);

namespace BootstrapSymfonyApp\Demo;

use Ucp\Sdk\Contract\OrderCapabilityInterface;
use Ucp\Sdk\Model\Common\Link;
use Ucp\Sdk\Model\Common\Message;
use Ucp\Sdk\Model\Common\Money;
use Ucp\Sdk\Model\Order\OrderView;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\RequestContext;

final class DemoOrderCapability implements OrderCapabilityInterface
{
    /**
     * @var array<string, OrderView>
     */
    private static array $orders = [];

    public function describe(): CapabilityDescriptor
    {
        return new CapabilityDescriptor(
            'dev.ucp.shopping.order',
            '2026-04-08',
            'https://ucp.dev/specification/order/',
            'https://ucp.dev/schemas/shopping/order.json',
        );
    }

    public function getOrder(string $id, RequestContext $context): OrderView
    {
        return self::$orders[$id] ?? new OrderView(
            $id,
            'EUR',
            [],
            [new Money('grand_total', 0.0)],
            [new Message('error', 'Demo order not found.', 'warning', 'order_not_found')],
            [new Link('order', 'https://example.com/order/' . $id, 'Order details')],
        );
    }

    public static function remember(OrderView $order): void
    {
        self::$orders[$order->id] = $order;
    }
}
