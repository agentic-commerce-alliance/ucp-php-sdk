<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Catalog;

use Ucp\Sdk\Model\Common\MonetaryAmount;

final class Product
{
    /**
     * @param array<string, bool|float|int|string|null|array<string, bool|float|int|string|null>|list<bool|float|int|string|null>> $extra
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

        $data = array_filter([
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
        ], static fn (mixed $value): bool => $value !== null);

        /** @var array{id: string, title: string, description: array{plain: string}, price_range: array{min: array{amount: int, currency: string}, max: array{amount: int, currency: string}}, image_url?: string, variants: list<array{id: string, title: string, description: array{plain: string}, price: array{amount: int, currency: string}}>} $payload */
        $payload = array_merge($data, $this->extra);

        return $payload;
    }
}
