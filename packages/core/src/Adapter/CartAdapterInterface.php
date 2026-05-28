<?php

declare(strict_types=1);

namespace Ucp\Sdk\Adapter;

use Ucp\Sdk\Adapter\Model\CartRecord;
use Ucp\Sdk\Model\Cart\CartCreateRequest;
use Ucp\Sdk\Model\Cart\CartUpdateRequest;
use Ucp\Sdk\Model\RequestContext;

interface CartAdapterInterface
{
    public function createCart(CartCreateRequest $request, RequestContext $context): CartRecord;

    public function getCart(string $id, RequestContext $context): CartRecord;

    public function updateCart(CartUpdateRequest $request, RequestContext $context): CartRecord;

    public function cancelCart(string $id, RequestContext $context): CartRecord;
}
