<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Catalog;

final readonly class Product
{
    /**
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public string $id,
        public string $title,
        public float $price,
        public ?string $imageUrl = null,
        public array $extra = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge(array_filter([
            'id' => $this->id,
            'title' => $this->title,
            'price' => $this->price,
            'image_url' => $this->imageUrl,
        ], static fn (mixed $value): bool => $value !== null), $this->extra);
    }
}
