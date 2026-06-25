<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Catalog;

use Ucp\Sdk\Model\Protocol\UcpOperationPayload;

final readonly class CatalogLookupResponse implements UcpOperationPayload
{
    /**
     * @param list<Product> $products
     */
    public function __construct(
        public array $products,
    ) {
    }

    /**
     * @return array{products: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'products' => array_map(static fn (Product $product): array => $product->toArray(), $this->products),
        ];
    }

    /**
     * @return array{products: list<array<string, mixed>>}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
