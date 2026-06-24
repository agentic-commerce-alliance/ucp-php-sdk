<?php

declare(strict_types=1);

namespace Ucp\Sdk\Contract;

use Ucp\Sdk\Model\Catalog\CatalogLookupRequest;
use Ucp\Sdk\Model\Catalog\CatalogProductRequest;
use Ucp\Sdk\Model\Catalog\CatalogSearchRequest;
use Ucp\Sdk\Model\Catalog\CatalogSearchResponse;
use Ucp\Sdk\Model\Catalog\Product;
use Ucp\Sdk\Model\RequestContext;

/**
 * Catalog operations for the shopping flow.
 *
 * Reference: https://ucp.dev/specification/overview/
 */
interface CatalogCapabilityInterface extends CapabilityInterface
{
    public function search(CatalogSearchRequest $request, RequestContext $context): CatalogSearchResponse;

    /**
     * @return list<Product>
     */
    public function lookup(CatalogLookupRequest $request, RequestContext $context): array;

    public function getProduct(CatalogProductRequest $request, RequestContext $context): Product;
}
