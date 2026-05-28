<?php

declare(strict_types=1);

namespace Ucp\Sdk\Adapter;

use Ucp\Sdk\Adapter\Model\ProductRecord;
use Ucp\Sdk\Model\Catalog\CatalogLookupRequest;
use Ucp\Sdk\Model\Catalog\CatalogSearchRequest;
use Ucp\Sdk\Model\RequestContext;

interface CatalogAdapterInterface
{
    /**
     * @return list<ProductRecord>
     */
    public function search(CatalogSearchRequest $request, RequestContext $context): array;

    /**
     * @return list<ProductRecord>
     */
    public function lookup(CatalogLookupRequest $request, RequestContext $context): array;

    public function getProduct(string $id, RequestContext $context): ProductRecord;
}
