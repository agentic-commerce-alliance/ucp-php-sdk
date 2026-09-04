<?php

declare(strict_types=1);

namespace MerchantSymfonyApp\Support;

use Ucp\Sdk\Exception\ResourceNotFoundException;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Checkout\DiscountCode;
use Ucp\Sdk\Model\Common\LineItem;
use Ucp\Sdk\Model\Common\Money;

final class PriceCalculator
{
    public function __construct(
        private readonly ProductCatalog $catalog,
        private readonly MerchantSettings $settings,
        private readonly FulfillmentPlanner $fulfillmentPlanner = new FulfillmentPlanner(),
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
     * @param array<string, mixed>|null $fulfillment the planned fulfillment object, not the request selection
     * @return list<Money>
     */
    public function calculateTotals(array $lineItems, array $discounts = [], ?array $fulfillment = null): array
    {
        $subtotal = 0.0;
        foreach ($lineItems as $lineItem) {
            $subtotal += $lineItem->price * $lineItem->quantity;
        }

        $discountAmount = 0.0;
        foreach ($discounts as $discount) {
            $discountAmount += self::discountAmount($discount->code, $subtotal);
        }

        // Nothing until the platform selects an option. Quoting a shipping charge against a
        // choice nobody made produces a total the buyer never agreed to, and it makes the
        // subtotal-plus-fulfillment arithmetic unverifiable from the response alone.
        $shipping = $this->fulfillmentPlanner->selectedOptionAmount($fulfillment);

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

    /**
     * The discounts that actually applied, as the response reports them.
     *
     * A negative `discount` entry in `totals` says how much came off; it does not say which
     * code produced it, and with several codes in play there is no way to work that out from
     * the total alone. `discounts.applied[]` is what makes a discount attributable -- and it
     * carries `allocations[]` so a platform can show which line the money came off.
     *
     * Unknown codes are simply absent: a code that matched nothing is not an applied discount,
     * and reporting it with a zero amount would claim it did something.
     *
     * @param list<LineItem> $lineItems
     * @param list<DiscountCode> $discounts
     *
     * @return list<array<string, mixed>>
     */
    public function appliedDiscounts(array $lineItems, array $discounts): array
    {
        $subtotal = 0.0;
        foreach ($lineItems as $lineItem) {
            $subtotal += $lineItem->price * $lineItem->quantity;
        }

        $applied = [];
        foreach ($discounts as $discount) {
            $amount = self::discountAmount($discount->code, $subtotal);
            if ($amount <= 0.0) {
                continue;
            }

            $minorUnits = (int) round($amount * 100);
            $applied[] = [
                'code' => $discount->code,
                'title' => self::discountTitle($discount->code),
                'amount' => $minorUnits,
                'automatic' => false,
                'method' => 'across',
                'allocations' => $this->allocations($lineItems, $subtotal, $minorUnits),
            ];
        }

        return $applied;
    }

    /**
     * Split a discount across the line items it came off, in whole minor units.
     *
     * The allocations have to sum to the discount exactly. Rounding each share independently
     * loses or invents a unit whenever the split is uneven, so the last line absorbs whatever
     * the earlier roundings left over.
     *
     * @param list<LineItem> $lineItems
     *
     * @return list<array<string, mixed>>
     */
    private function allocations(array $lineItems, float $subtotal, int $minorUnits): array
    {
        if ($lineItems === [] || $subtotal <= 0.0) {
            return [];
        }

        $allocations = [];
        $assigned = 0;
        $lastIndex = count($lineItems) - 1;

        foreach ($lineItems as $index => $lineItem) {
            $share = $index === $lastIndex
                ? $minorUnits - $assigned
                : (int) round($minorUnits * ($lineItem->price * $lineItem->quantity) / $subtotal);

            $assigned += $share;
            $allocations[] = [
                'path' => sprintf('$.line_items[%d]', $index),
                'amount' => $share,
            ];
        }

        return $allocations;
    }

    private static function discountTitle(string $code): string
    {
        $discount = self::lookupDiscount($code);
        if ($discount === null) {
            return $code;
        }

        return $discount['type'] === 'percentage'
            ? sprintf('%s%% off', rtrim(rtrim(number_format($discount['value'] * 100, 1, '.', ''), '0'), '.'))
            : sprintf('%.2f off', $discount['value']);
    }

    public static function knowsDiscountCode(string $code): bool
    {
        return self::lookupDiscount($code) !== null;
    }

    /**
     * Discount codes are matched case-insensitively, which `discounts.codes` requires.
     *
     * @return array{type: 'percentage'|'fixed', value: float}|null
     */
    private static function lookupDiscount(string $code): ?array
    {
        return self::DISCOUNT_CODES[strtoupper(trim($code))] ?? null;
    }

    private static function discountAmount(string $code, float $subtotal): float
    {
        $discount = self::lookupDiscount($code);
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
