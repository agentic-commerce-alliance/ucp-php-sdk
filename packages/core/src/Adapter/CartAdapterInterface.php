<?php

declare(strict_types=1);

namespace Ucp\Sdk\Adapter;

use Ucp\Sdk\Model\Cart\Cart;
use Ucp\Sdk\Model\Cart\CartCreateRequest;
use Ucp\Sdk\Model\Cart\CartUpdateRequest;
use Ucp\Sdk\Model\RequestContext;

/**
 * Optional platform-facing contract for cart mapping.
 *
 * Projects may implement CartCapabilityInterface directly instead when they do not
 * need a separate adapter layer.
 */
interface CartAdapterInterface
{
    public function createCart(CartCreateRequest $request, RequestContext $context): Cart;

    public function getCart(string $id, RequestContext $context): Cart;

    public function updateCart(CartUpdateRequest $request, RequestContext $context): Cart;

    public function cancelCart(string $id, RequestContext $context): Cart;
}
