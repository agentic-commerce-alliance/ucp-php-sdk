<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Catalog;

use Ucp\Sdk\Model\Protocol\UcpOperationPayload;

final class CatalogProductResponse implements UcpOperationPayload
{
    public function __construct(
        public readonly Product $product,
    ) {
    }

    /**
     * @return array{product: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'product' => $this->product->toArray(),
        ];
    }

    /**
     * @return array{product: array<string, mixed>}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
