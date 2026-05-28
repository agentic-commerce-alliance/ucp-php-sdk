<?php

declare(strict_types=1);

namespace Ucp\Sdk\Adapter\Model;

use Ucp\Sdk\Model\Catalog\Product;

final readonly class ProductRecord
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

    public function toProduct(): Product
    {
        return new Product($this->id, $this->title, $this->price, $this->imageUrl, $this->extra);
    }
}
