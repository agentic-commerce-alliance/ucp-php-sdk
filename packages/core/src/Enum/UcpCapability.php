<?php

declare(strict_types=1);

namespace Ucp\Sdk\Enum;

enum UcpCapability: string
{
    case CatalogSearch = 'dev.ucp.shopping.catalog.search';
    case CatalogLookup = 'dev.ucp.shopping.catalog.lookup';
    case Cart = 'dev.ucp.shopping.cart';
    case Checkout = 'dev.ucp.shopping.checkout';
    case Order = 'dev.ucp.shopping.order';
    case Discount = 'dev.ucp.shopping.discount';
    case Fulfillment = 'dev.ucp.shopping.fulfillment';
    case BuyerConsent = 'dev.ucp.shopping.buyer_consent';
    case IdentityLinking = 'dev.ucp.common.identity_linking';
}
