<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Common;

final readonly class LineItem
{
    /**
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public string $id,
        public string $title,
        public float $price,
        public int $quantity = 1,
        public ?string $imageUrl = null,
        public array $extra = [],
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
