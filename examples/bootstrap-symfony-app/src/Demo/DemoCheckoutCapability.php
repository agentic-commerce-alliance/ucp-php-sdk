<?php

declare(strict_types=1);

namespace BootstrapSymfonyApp\Demo;

use Ucp\Sdk\Contract\CheckoutCapabilityInterface;
use Ucp\Sdk\Enum\CheckoutStatus;
use Ucp\Sdk\Enum\UcpProtocolVersion;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Checkout\CheckoutCreateRequest;
use Ucp\Sdk\Model\Checkout\CheckoutUpdateRequest;
use Ucp\Sdk\Model\Checkout\OrderConfirmation;
use Ucp\Sdk\Model\Common\Link;
use Ucp\Sdk\Model\Common\Money;
use Ucp\Sdk\Model\Order\OrderView;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\RequestContext;

final class DemoCheckoutCapability implements CheckoutCapabilityInterface
{
    /**
     * @var array<string, Checkout>
     */
    private static array $checkouts = [];

    public function describe(): CapabilityDescriptor
    {
        return new CapabilityDescriptor(
            'dev.ucp.shopping.checkout',
            UcpProtocolVersion::current()->value,
            'https://ucp.dev/specification/checkout/',
            'https://ucp.dev/schemas/shopping/checkout.json',
        );
    }

    public function createCheckout(CheckoutCreateRequest $request, RequestContext $context): Checkout
    {
        $checkout = new Checkout(
            'chk-demo',
            CheckoutStatus::Incomplete,
            'EUR',
            $request->lineItems,
            [new Money('subtotal', 19.99), new Money('total', 19.99)],
            links: [new Link('privacy', 'https://example.com/privacy')],
            buyer: $request->buyer,
            continueUrl: 'https://example.com/continue/chk-demo',
        );
        self::$checkouts[$checkout->id] = $checkout;

        return $checkout;
    }

    public function getCheckout(string $id, RequestContext $context): Checkout
    {
        return self::$checkouts[$id] ?? $this->createCheckout(new CheckoutCreateRequest([]), $context);
    }

    public function updateCheckout(CheckoutUpdateRequest $request, RequestContext $context): Checkout
    {
        $checkout = new Checkout(
            $request->id,
            CheckoutStatus::ReadyForComplete,
            'EUR',
            $request->lineItems,
            [new Money('subtotal', 24.99), new Money('total', 24.99)],
            links: [new Link('privacy', 'https://example.com/privacy')],
            buyer: $request->buyer,
        );
        self::$checkouts[$request->id] = $checkout;

        return $checkout;
    }

    public function completeCheckout(string $id, RequestContext $context): Checkout
    {
        $checkout = self::$checkouts[$id] ?? $this->createCheckout(new CheckoutCreateRequest([]), $context);
        $orderId = 'order-demo';
        DemoOrderCapability::remember(new OrderView(
            $orderId,
            $checkout->currency,
            $checkout->lineItems,
            $checkout->totals,
            $checkout->messages,
            [new Link('order', 'https://example.com/order/' . $orderId, 'Order details')],
            $checkout->buyer,
            gmdate('c'),
            checkoutId: $checkout->id,
            permalinkUrl: 'https://example.com/order/' . $orderId,
            fulfillment: [],
        ));

        return new Checkout(
            $checkout->id,
            CheckoutStatus::Completed,
            $checkout->currency,
            $checkout->lineItems,
            $checkout->totals,
            $checkout->messages,
            $checkout->links,
            $checkout->buyer,
            order: new OrderConfirmation($orderId, 'https://example.com/order/' . $orderId),
            extra: $checkout->extra,
        );
    }

    public function cancelCheckout(string $id, RequestContext $context): Checkout
    {
        $checkout = self::$checkouts[$id] ?? $this->createCheckout(new CheckoutCreateRequest([]), $context);

        return new Checkout($checkout->id, CheckoutStatus::Canceled, $checkout->currency, $checkout->lineItems, $checkout->totals, $checkout->messages, $checkout->links, $checkout->buyer);
    }
}
