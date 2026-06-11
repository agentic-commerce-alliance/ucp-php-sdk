<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Catalog;

final class CatalogLookupRequest
{
    /**
     * @param list<string> $ids
     */
    public function __construct(
        public readonly array $ids,
    ) {
    }
}
