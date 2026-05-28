<?php

declare(strict_types=1);

namespace MerchantSymfonyApp\Support;

use Ucp\Sdk\Model\Checkout\DiscountCode;
use Ucp\Sdk\Model\Checkout\FulfillmentSelection;
use Ucp\Sdk\Model\Common\LineItem;
use Ucp\Sdk\Model\Common\Money;

final readonly class PriceCalculator
{
    public function __construct(
        private ProductCatalog $catalog,
        private MerchantSettings $settings,
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
                $lineItems[] = $requestedItem;
                continue;
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
            if ($discount->code === 'SAVE10') {
                $discountAmount += round($subtotal * 0.10, 2);
            }
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

        return [
            new Money('subtotal', round($subtotal, 2), $this->format($subtotal)),
            new Money('discount', round(-1 * $discountAmount, 2), $this->format(-1 * $discountAmount)),
            new Money('shipping', round($shipping, 2), $this->format($shipping)),
            new Money('tax', $tax, $this->format($tax)),
            new Money('grand_total', $grandTotal, $this->format($grandTotal)),
        ];
    }

    private function format(float $amount): string
    {
        return sprintf('%s %.2f', $this->settings->currency, $amount);
    }
}
