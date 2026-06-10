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
        return array_merge(array_filter([
            'id' => $this->id,
            'title' => $this->title,
            'price' => $this->price,
            'image_url' => $this->imageUrl,
        ], static fn (mixed $value): bool => $value !== null), $this->extra);
    }
}
