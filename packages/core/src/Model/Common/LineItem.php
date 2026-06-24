<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Common;

final class LineItem
{
    /**
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly float $price,
        public readonly int $quantity = 1,
        public readonly ?string $imageUrl = null,
        public readonly array $extra = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $amount = self::minorUnits($this->price);
        $total = $amount * $this->quantity;

        return array_merge([
            'id' => 'li_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $this->id),
            'item' => array_filter([
                'id' => $this->id,
                'title' => $this->title,
                'price' => $amount,
                'image_url' => $this->imageUrl,
            ], static fn (mixed $value): bool => $value !== null),
            'quantity' => $this->quantity,
            'totals' => [
                ['type' => 'subtotal', 'amount' => $total],
                ['type' => 'total', 'amount' => $total],
            ],
        ], $this->extra);
    }

    private static function minorUnits(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
