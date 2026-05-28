<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Catalog;

final readonly class CatalogSearchRequest
{
    /**
     * @param array<string, scalar|list<scalar>|null> $filters
     */
    public function __construct(
        public string $query,
        public int $limit = 20,
        public ?string $cursor = null,
        public array $filters = [],
    ) {
    }
}
