<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Model\Cart\Cart;
use Ucp\Sdk\Model\Common\Money;

final class CartTest extends TestCase
{
    #[Test]
    public function itSerializesSpecCartFieldsWithoutAnExtraKey(): void
    {
        $cart = new Cart('cart-1', [], 'EUR', [new Money('total', 10.0)]);

        $payload = $cart->toArray();

        self::assertSame(['id', 'line_items', 'currency', 'totals', 'messages'], array_keys($payload));
        self::assertArrayNotHasKey('extra', $payload);
    }

    #[Test]
    public function itMergesCapabilityExtensionFieldsIntoThePayload(): void
    {
        // `discount.json` extends the cart with `discounts`, and cart.get,
        // cart.update and discount.apply all answer with a Cart. Without this an
        // adapter had nowhere to put the applied-discount breakdown.
        $cart = new Cart('cart-1', [], 'EUR', [], [], [
            'discounts' => ['applied' => [['title' => 'Summer Sale', 'amount' => -200, 'code' => 'SUMMER']]],
        ]);

        $payload = $cart->toArray();

        self::assertSame(
            ['applied' => [['title' => 'Summer Sale', 'amount' => -200, 'code' => 'SUMMER']]],
            $payload['discounts'] ?? null,
        );
        self::assertArrayNotHasKey('extra', $payload);
    }

    #[Test]
    public function itKeepsExtraOutOfTheWayOfSpecFields(): void
    {
        $cart = new Cart('cart-1', [], 'EUR', [], [], ['id' => 'spoofed']);

        // array_merge lets extra win, the same way Checkout and OrderView behave.
        // Pinned so the precedence is a decision rather than an accident.
        self::assertSame('spoofed', $cart->toArray()['id']);
    }

    #[Test]
    public function itSerializesTheSameWayThroughJsonSerialize(): void
    {
        $cart = new Cart('cart-1', [], 'EUR', [], [], ['discounts' => ['codes' => ['SUMMER']]]);

        self::assertSame($cart->toArray(), $cart->jsonSerialize());
    }
}
