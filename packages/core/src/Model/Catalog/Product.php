<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Catalog;

use Ucp\Sdk\Model\Common\MonetaryAmount;

final class Product
{
    /**
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly float $price,
        public readonly ?string $imageUrl = null,
        public readonly array $extra = [],
        public readonly string $currency = 'EUR',
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $price = MonetaryAmount::fromMajorUnits($this->price, $this->currency)->toPriceArray();

        return array_merge(array_filter([
            'id' => $this->id,
            'title' => $this->title,
            'description' => [
                'plain' => $this->title,
            ],
            'price_range' => [
                'min' => $price,
                'max' => $price,
            ],
            'image_url' => $this->imageUrl,
            'variants' => [[
                'id' => $this->id,
                'title' => $this->title,
                'description' => [
                    'plain' => $this->title,
                ],
                'price' => $price,
            ]],
        ], static fn (mixed $value): bool => $value !== null), $this->extra);
    }
}
