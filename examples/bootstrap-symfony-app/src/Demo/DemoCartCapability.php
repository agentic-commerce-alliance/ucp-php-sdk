<?php

declare(strict_types=1);

namespace BootstrapSymfonyApp\Demo;

use Ucp\Sdk\Contract\CartCapabilityInterface;
use Ucp\Sdk\Model\Cart\Cart;
use Ucp\Sdk\Model\Cart\CartCreateRequest;
use Ucp\Sdk\Model\Cart\CartUpdateRequest;
use Ucp\Sdk\Model\Common\Message;
use Ucp\Sdk\Model\Common\Money;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\RequestContext;

final class DemoCartCapability implements CartCapabilityInterface
{
    /**
     * @var array<string, Cart>
     */
    private static array $carts = [];

    public function describe(): CapabilityDescriptor
    {
        return new CapabilityDescriptor(
            'dev.ucp.shopping.cart',
            '2026-04-08',
            'https://ucp.dev/specification/overview/',
            'https://ucp.dev/schemas/shopping/cart.json',
        );
    }

    public function createCart(CartCreateRequest $request, RequestContext $context): Cart
    {
        $cart = new Cart('cart-demo', $request->lineItems, 'EUR', [new Money('subtotal', 19.99), new Money('total', 19.99)], [new Message('info', 'cart created')]);
        self::$carts[$cart->id] = $cart;

        return $cart;
    }

    public function getCart(string $id, RequestContext $context): Cart
    {
        return self::$carts[$id] ?? new Cart($id, [], 'EUR', [new Money('subtotal', 0.0), new Money('total', 0.0)]);
    }

    public function updateCart(CartUpdateRequest $request, RequestContext $context): Cart
    {
        $cart = new Cart($request->id, $request->lineItems, 'EUR', [new Money('subtotal', 24.99), new Money('total', 24.99)]);
        self::$carts[$request->id] = $cart;

        return $cart;
    }

    public function cancelCart(string $id, RequestContext $context): Cart
    {
        return self::$carts[$id] ?? new Cart($id, [], 'EUR', [new Money('subtotal', 0.0), new Money('total', 0.0)]);
    }
}
