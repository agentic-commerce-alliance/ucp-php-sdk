<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Catalog;

final class CatalogSearchRequest
{
    /**
     * @param array<string, scalar|list<scalar>|null> $filters
     */
    public function __construct(
        public readonly string $query,
        public readonly int $limit = 20,
        public readonly ?string $cursor = null,
        public readonly array $filters = [],
    ) {
    }
}
