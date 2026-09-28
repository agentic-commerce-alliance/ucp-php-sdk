<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Catalog;

use Ucp\Sdk\Model\Common\Media;
use Ucp\Sdk\Model\Common\MonetaryAmount;
use Ucp\Sdk\Model\Common\Unit;
use Ucp\Sdk\Model\Common\UnitPrice;

final class Product
{
    /**
     * @param array<string, bool|float|int|string|null|array<string, bool|float|int|string|null>|list<bool|float|int|string|null>> $extra
     * @param string|null $description Plain-text product description. Falls back to the title when null so the schema-required `description` field is always populated.
     * @param list<Media>|null $media Ordered product media. Null leaves the legacy payload unchanged; an empty list explicitly publishes no media.
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
        public readonly ?array $media = null,
    ) {
    }

    /**
     * @return array{
     *     id: string,
     *     title: string,
     *     description: array{plain: string},
     *     price_range: array{min: array{amount: int, currency: string}, max: array{amount: int, currency: string}},
     *     image_url?: string,
     *     media?: list<array{type: string, url: string, alt_text?: string, width?: int, height?: int}>,
     *     variants: list<array{id: string, title: string, description: array{plain: string}, price: array{amount: int, currency: string}}>
     * }
     */
    public function toArray(): array
    {
        $price = MonetaryAmount::fromMajorUnits($this->price, $this->currency)->toPriceArray();
        $description = $this->description ?? $this->title;
        $media = $this->media === null ? null : array_map(
            static fn (Media $item): array => $item->toArray(),
            $this->media,
        );
        // The legacy image URL must not point to a video or 3D model.
        $imageUrl = $this->imageUrl;
        if ($imageUrl === null && isset($this->media[0]) && $this->media[0]->type === 'image') {
            $imageUrl = $this->media[0]->url;
        }

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
            'image_url' => $imageUrl,
            'media' => $media,
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

        /** @var array{id: string, title: string, description: array{plain: string}, price_range: array{min: array{amount: int, currency: string}, max: array{amount: int, currency: string}}, image_url?: string, media?: list<array{type: string, url: string, alt_text?: string, width?: int, height?: int}>, variants: list<array{id: string, title: string, description: array{plain: string}, price: array{amount: int, currency: string}}>} $payload */
        $payload = array_merge($data, $this->extra);

        return $payload;
    }
}
