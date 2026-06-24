<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Catalog;

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
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $price = self::minorUnits($this->price);

        return array_merge(array_filter([
            'id' => $this->id,
            'title' => $this->title,
            'description' => [
                'plain' => $this->title,
            ],
            'price_range' => [
                'min' => ['amount' => $price, 'currency' => 'EUR'],
                'max' => ['amount' => $price, 'currency' => 'EUR'],
            ],
            'image_url' => $this->imageUrl,
            'variants' => [[
                'id' => $this->id,
                'title' => $this->title,
                'description' => [
                    'plain' => $this->title,
                ],
                'price' => ['amount' => $price, 'currency' => 'EUR'],
            ]],
        ], static fn (mixed $value): bool => $value !== null), $this->extra);
    }

    private static function minorUnits(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
