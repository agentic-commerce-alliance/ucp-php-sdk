<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Catalog;

use Ucp\Sdk\Model\Common\MonetaryAmount;
use Ucp\Sdk\Model\Common\Unit;
use Ucp\Sdk\Model\Common\UnitPrice;

final class Product
{
    /**
     * @param array<string, bool|float|int|string|null|array<string, bool|float|int|string|null>|list<bool|float|int|string|null>> $extra
     * @param string|null $description Plain-text product description. Falls back to the title when null so the schema-required `description` field is always populated.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly float $price,
        public readonly ?string $imageUrl = null,
        public readonly array $extra = [],
        public readonly string $currency = 'EUR',
        public readonly ?string $description = null,
        /** Sale basis a quantity of this product is denominated in. Absent means `each`. */
        public readonly ?Unit $quantityUnit = null,
        public readonly ?UnitPrice $unitPrice = null,
    ) {
    }

    /**
     * @return array{
     *     id: string,
     *     title: string,
     *     description: array{plain: string},
     *     price_range: array{min: array{amount: int, currency: string}, max: array{amount: int, currency: string}},
     *     image_url?: string,
     *     variants: list<array{id: string, title: string, description: array{plain: string}, price: array{amount: int, currency: string}}>
     * }
     */
    public function toArray(): array
    {
        $price = MonetaryAmount::fromMajorUnits($this->price, $this->currency)->toPriceArray();
        $description = $this->description ?? $this->title;

        $data = array_filter([
            'id' => $this->id,
            'title' => $this->title,
            'description' => [
                'plain' => $description,
            ],
            'price_range' => [
                'min' => $price,
                'max' => $price,
            ],
            'image_url' => $this->imageUrl,
            'quantity_unit' => $this->quantityUnit?->toArray(),
            'unit_price' => $this->unitPrice?->toArray(),
            'variants' => [[
                'id' => $this->id,
                'title' => $this->title,
                'description' => [
                    'plain' => $description,
                ],
                'price' => $price,
                // `variant.json` carries both as well, and a variant is what a buyer actually
                // selects, so a sale basis that only appeared on the parent would be lost.
                // Filtered separately: the outer array_filter does not reach in here, and an
                // explicit `"quantity_unit": null` is not the same as absence, which the spec
                // reads as the default `each`.
                ...array_filter([
                    'quantity_unit' => $this->quantityUnit?->toArray(),
                    'unit_price' => $this->unitPrice?->toArray(),
                ], static fn (mixed $value): bool => $value !== null),
            ]],
        ], static fn (mixed $value): bool => $value !== null);

        /** @var array{id: string, title: string, description: array{plain: string}, price_range: array{min: array{amount: int, currency: string}, max: array{amount: int, currency: string}}, image_url?: string, variants: list<array{id: string, title: string, description: array{plain: string}, price: array{amount: int, currency: string}}>} $payload */
        $payload = array_merge($data, $this->extra);

        return $payload;
    }
}
