<?php

declare(strict_types=1);

namespace BootstrapSymfonyApp\Demo;

use Ucp\Sdk\Contract\CatalogCapabilityInterface;
use Ucp\Sdk\Enum\UcpProtocolVersion;
use Ucp\Sdk\Model\Catalog\CatalogLookupRequest;
use Ucp\Sdk\Model\Catalog\CatalogProductRequest;
use Ucp\Sdk\Model\Catalog\CatalogSearchRequest;
use Ucp\Sdk\Model\Catalog\CatalogSearchResponse;
use Ucp\Sdk\Model\Catalog\Product;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\RequestContext;

final class DemoCatalogCapability implements CatalogCapabilityInterface
{
    public function describe(): CapabilityDescriptor
    {
        return new CapabilityDescriptor(
            'dev.ucp.shopping.catalog.search',
            UcpProtocolVersion::current()->value,
            'https://ucp.dev/specification/overview/',
            'https://ucp.dev/schemas/shopping/catalog-search.json',
        );
    }

    public function search(CatalogSearchRequest $request, RequestContext $context): CatalogSearchResponse
    {
        return new CatalogSearchResponse([
            new Product('sku-1', 'Demo Product', 19.99),
            new Product('sku-2', 'Another Product', 29.99),
        ]);
    }

    public function lookup(CatalogLookupRequest $request, RequestContext $context): array
    {
        return array_map(
            static fn (string $id): Product => new Product($id, 'Lookup ' . $id, 9.99),
            $request->ids,
        );
    }

    public function getProduct(CatalogProductRequest $request, RequestContext $context): Product
    {
        return new Product($request->id, 'Lookup ' . $request->id, 9.99);
    }
}
