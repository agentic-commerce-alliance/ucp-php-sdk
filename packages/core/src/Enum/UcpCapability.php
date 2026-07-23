<?php

declare(strict_types=1);

namespace Ucp\Sdk\Enum;

enum UcpCapability: string
{
    case CatalogSearch = 'dev.ucp.shopping.catalog.search';
    case CatalogLookup = 'dev.ucp.shopping.catalog.lookup';
    case CatalogProduct = 'dev.ucp.shopping.catalog.product';
    case Cart = 'dev.ucp.shopping.cart';
    case Checkout = 'dev.ucp.shopping.checkout';
    case Ap2Mandate = 'dev.ucp.shopping.ap2_mandate';
    case Order = 'dev.ucp.shopping.order';
}
