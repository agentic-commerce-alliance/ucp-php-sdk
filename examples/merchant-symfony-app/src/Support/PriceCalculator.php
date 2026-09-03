<?php

declare(strict_types=1);

namespace MerchantSymfonyApp\Support;

use Ucp\Sdk\Exception\ResourceNotFoundException;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Checkout\DiscountCode;
use Ucp\Sdk\Model\Checkout\FulfillmentSelection;
use Ucp\Sdk\Model\Common\LineItem;
use Ucp\Sdk\Model\Common\Money;

final class PriceCalculator
{
    public function __construct(
        private readonly ProductCatalog $catalog,
        private readonly MerchantSettings $settings,
    ) {
    }

    /**
     * @param list<LineItem> $requestedItems
     * @return list<LineItem>
     */
    public function canonicalizeLineItems(array $requestedItems): array
    {
        $lineItems = [];

        foreach ($requestedItems as $requestedItem) {
            $product = $this->catalog->find($requestedItem->id);
            if ($product === null) {
                throw new ResourceNotFoundException(sprintf('Product "%s" does not exist.', $requestedItem->id));
            }

            // Stock was published in every catalog response and checked nowhere, so the field
            // was decoration: an agent could fill a cart with items this merchant cannot ship
            // and only find out never.
            if ($product['stock'] < $requestedItem->quantity) {
                throw new ValidationException(
                    sprintf('Product "%s" is out of stock.', $product['id']),
                    [sprintf('$.line_items[?(@.id == "%s")].quantity exceeds available stock', $product['id'])],
                );
            }

            $lineItems[] = new LineItem(
                $product['id'],
                $product['title'],
                $product['price'],
                $requestedItem->quantity,
                $product['image_url'],
                [
                    'availability' => [
                        'stock' => $product['stock'],
                    ],
                    'category' => $product['category'],
                ],
            );
        }

        return $lineItems;
    }

    /**
     * @param list<LineItem> $lineItems
     * @param list<DiscountCode> $discounts
     * @return list<Money>
     */
    public function calculateTotals(array $lineItems, array $discounts = [], ?FulfillmentSelection $fulfillment = null): array
    {
        $subtotal = 0.0;
        foreach ($lineItems as $lineItem) {
            $subtotal += $lineItem->price * $lineItem->quantity;
        }

        $discountAmount = 0.0;
        foreach ($discounts as $discount) {
            $discountAmount += self::discountAmount($discount->code, $subtotal);
        }

        $shipping = 4.90;
        if ($fulfillment?->methodId === 'pickup-store') {
            $shipping = 0.0;
        }

        if ($fulfillment?->methodId === 'express-shipping') {
            $shipping = 12.90;
        }

        $taxableBase = max(0.0, $subtotal - $discountAmount + $shipping);
        $tax = round($taxableBase * 0.19, 2);
        $grandTotal = round($taxableBase + $tax, 2);

        $totals = [
            new Money('subtotal', round($subtotal, 2), $this->format($subtotal)),
        ];

        if ($discountAmount > 0.0) {
            $totals[] = new Money('discount', round(-1 * $discountAmount, 2), $this->format(-1 * $discountAmount));
        }

        return [
            ...$totals,
            new Money('fulfillment', round($shipping, 2), $this->format($shipping)),
            new Money('tax', $tax, $this->format($tax)),
            new Money('total', $grandTotal, $this->format($grandTotal)),
        ];
    }

    /**
     * The codes this demo merchant honours.
     *
     * Percentage and fixed-amount forms, because a conformance suite is configured with one of
     * each and asserts the resulting total.
     *
     * @var array<string, array{type: 'percentage'|'fixed', value: float}>
     */
    public const DISCOUNT_CODES = [
        'SAVE10' => ['type' => 'percentage', 'value' => 0.10],
        'SAVE20' => ['type' => 'percentage', 'value' => 0.20],
        'FIVEOFF' => ['type' => 'fixed', 'value' => 5.0],
    ];

    public static function knowsDiscountCode(string $code): bool
    {
        return array_key_exists($code, self::DISCOUNT_CODES);
    }

    private static function discountAmount(string $code, float $subtotal): float
    {
        $discount = self::DISCOUNT_CODES[$code] ?? null;
        if ($discount === null) {
            return 0.0;
        }

        return $discount['type'] === 'percentage'
            ? round($subtotal * $discount['value'], 2)
            : min($discount['value'], round($subtotal, 2));
    }

    private function format(float $amount): string
    {
        return sprintf('%s %.2f', $this->settings->currency, $amount);
    }
}
