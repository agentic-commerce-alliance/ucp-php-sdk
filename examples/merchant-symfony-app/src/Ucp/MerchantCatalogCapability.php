<?php

declare(strict_types=1);

namespace MerchantSymfonyApp\Ucp;

use MerchantSymfonyApp\Support\ProductCatalog;
use Ucp\Sdk\Contract\CatalogCapabilityInterface;
use Ucp\Sdk\Model\Catalog\CatalogLookupRequest;
use Ucp\Sdk\Model\Catalog\CatalogProductRequest;
use Ucp\Sdk\Model\Catalog\CatalogSearchRequest;
use Ucp\Sdk\Model\Catalog\CatalogSearchResponse;
use Ucp\Sdk\Model\Catalog\Product;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\RequestContext;

final class MerchantCatalogCapability implements CatalogCapabilityInterface
{
    public function __construct(
        private readonly ProductCatalog $catalog,
    ) {
    }

    public function describe(): CapabilityDescriptor
    {
        return new CapabilityDescriptor(
            'dev.ucp.shopping.catalog.search',
            '2026-04-08',
            'https://ucp.dev/specification/overview/',
            'https://ucp.dev/schemas/shopping/catalog-search.json',
            null,
            [
                'categories' => ['camping', 'cooking', 'hiking', 'accessories'],
                'price_currency' => 'EUR',
            ],
        );
    }

    public function search(CatalogSearchRequest $request, RequestContext $context): CatalogSearchResponse
    {
        return new CatalogSearchResponse(array_map(
            fn (array $product): Product => $this->toProduct($product),
            $this->catalog->search($request->query, $request->limit),
        ));
    }

    public function lookup(CatalogLookupRequest $request, RequestContext $context): array
    {
        return array_map(
            fn (array $product): Product => $this->toProduct($product),
            $this->catalog->findMany($request->ids),
        );
    }

    public function getProduct(CatalogProductRequest $request, RequestContext $context): Product
    {
        $product = $this->catalog->find($request->id);

        return $this->toProduct($product ?? [
            'id' => $request->id,
            'title' => 'Unknown product',
            'price' => 0.0,
            'image_url' => 'https://images.example.test/products/placeholder.jpg',
            'stock' => 0,
            'category' => 'unknown',
        ]);
    }

    /**
     * @param array{id: string, title: string, price: float, image_url: string, stock: int, category: string} $product
     */
    private function toProduct(array $product): Product
    {
        return new Product(
            $product['id'],
            $product['title'],
            $product['price'],
            $product['image_url'],
            [
                'stock' => $product['stock'],
                'category' => $product['category'],
            ],
        );
    }
}
