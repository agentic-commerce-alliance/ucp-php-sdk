<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Catalog;

final class CatalogSearchResponse
{
    /**
     * @param list<Product> $items
     */
    public function __construct(
        public readonly array $items,
        public readonly ?string $nextCursor = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'items' => array_map(static fn (Product $product): array => $product->toArray(), $this->items),
        ];

        if ($this->nextCursor !== null) {
            $data['next_cursor'] = $this->nextCursor;
        }

        return $data;
    }
}
