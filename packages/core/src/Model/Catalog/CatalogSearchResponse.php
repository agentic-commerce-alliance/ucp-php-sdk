<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Catalog;

use Ucp\Sdk\Model\Protocol\UcpOperationPayload;

final class CatalogSearchResponse implements UcpOperationPayload
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
     * @return array{products: list<array<string, mixed>>, pagination?: array{has_next_page: true, cursor: string}}
     */
    public function toArray(): array
    {
        $data = [
            'products' => array_map(static fn (Product $product): array => $product->toArray(), $this->items),
        ];

        if ($this->nextCursor !== null) {
            $data['pagination'] = [
                'has_next_page' => true,
                'cursor' => $this->nextCursor,
            ];
        }

        return $data;
    }

    /**
     * @return array{products: list<array<string, mixed>>, pagination?: array{has_next_page: true, cursor: string}}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
