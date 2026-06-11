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
        return array_merge([
            'item' => array_filter([
                'id' => $this->id,
                'title' => $this->title,
                'price' => $this->price,
                'image_url' => $this->imageUrl,
            ], static fn (mixed $value): bool => $value !== null),
            'quantity' => $this->quantity,
        ], $this->extra);
    }
}
