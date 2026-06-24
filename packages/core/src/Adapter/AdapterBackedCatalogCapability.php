<?php

declare(strict_types=1);

namespace Ucp\Sdk\Adapter;

use Ucp\Sdk\Contract\CatalogCapabilityInterface;
use Ucp\Sdk\Model\Catalog\CatalogLookupRequest;
use Ucp\Sdk\Model\Catalog\CatalogProductRequest;
use Ucp\Sdk\Model\Catalog\CatalogSearchRequest;
use Ucp\Sdk\Model\Catalog\CatalogSearchResponse;
use Ucp\Sdk\Model\Catalog\Product;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\RequestContext;

/**
 * Optional convenience wrapper for host applications that prefer small platform adapters
 * and a separate capability descriptor. Projects may implement CatalogCapabilityInterface
 * directly instead.
 */
final class AdapterBackedCatalogCapability implements CatalogCapabilityInterface
{
    public function __construct(
        private readonly CapabilityDescriptor $descriptor,
        private readonly CatalogAdapterInterface $adapter,
    ) {
    }

    public function describe(): CapabilityDescriptor
    {
        return $this->descriptor;
    }

    public function search(CatalogSearchRequest $request, RequestContext $context): CatalogSearchResponse
    {
        return new CatalogSearchResponse($this->adapter->search($request, $context));
    }

    public function lookup(CatalogLookupRequest $request, RequestContext $context): array
    {
        return $this->adapter->lookup($request, $context);
    }

    public function getProduct(CatalogProductRequest $request, RequestContext $context): Product
    {
        return $this->adapter->getProduct($request, $context);
    }
}
