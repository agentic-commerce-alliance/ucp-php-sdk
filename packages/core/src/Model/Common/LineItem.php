<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Common;

final class LineItem
{
    /**
     * @param array<string, bool|float|int|string|null|array<string, bool|float|int|string|null>|list<bool|float|int|string|null>> $extra
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly float $price,
        public readonly int $quantity = 1,
        public readonly ?string $imageUrl = null,
        public readonly array $extra = [],
        public readonly string $currency = 'EUR',
    ) {
    }

    /**
     * @return array{
     *     id: string,
     *     item: array{id: string, title: string, price: int, image_url?: string},
     *     quantity: int,
     *     totals: list<array{type: string, amount: int}>
     * }
     */
    public function toArray(): array
    {
        $amount = MonetaryAmount::fromMajorUnits($this->price, $this->currency)->minorUnits;
        $total = $amount * $this->quantity;

        /** @var array{id: string, item: array{id: string, title: string, price: int, image_url?: string}, quantity: int, totals: list<array{type: string, amount: int}>} $payload */
        $payload = array_merge([
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

        return $payload;
    }

}
