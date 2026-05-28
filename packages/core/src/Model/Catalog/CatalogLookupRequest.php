<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Catalog;

final readonly class CatalogLookupRequest
{
    /**
     * @param list<string> $ids
     */
    public function __construct(
        public array $ids,
    ) {
    }
}
