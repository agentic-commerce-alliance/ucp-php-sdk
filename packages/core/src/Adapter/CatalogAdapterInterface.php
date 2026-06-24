<?php

declare(strict_types=1);

namespace Ucp\Sdk\Adapter;

use Ucp\Sdk\Model\Catalog\CatalogLookupRequest;
use Ucp\Sdk\Model\Catalog\CatalogProductRequest;
use Ucp\Sdk\Model\Catalog\CatalogSearchRequest;
use Ucp\Sdk\Model\Catalog\Product;
use Ucp\Sdk\Model\RequestContext;

/**
 * Optional platform-facing contract for catalog mapping.
 *
 * Projects may implement CatalogCapabilityInterface directly instead when they do not
 * need a separate adapter layer.
 */
interface CatalogAdapterInterface
{
    /**
     * @return list<Product>
     */
    public function search(CatalogSearchRequest $request, RequestContext $context): array;

    /**
     * @return list<Product>
     */
    public function lookup(CatalogLookupRequest $request, RequestContext $context): array;

    public function getProduct(CatalogProductRequest $request, RequestContext $context): Product;
}
