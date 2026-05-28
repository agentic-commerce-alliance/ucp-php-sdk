<?php

declare(strict_types=1);

namespace Ucp\Sdk\Contract;

use Ucp\Sdk\Model\Cart\Cart;
use Ucp\Sdk\Model\Cart\CartCreateRequest;
use Ucp\Sdk\Model\Cart\CartUpdateRequest;
use Ucp\Sdk\Model\RequestContext;

/**
 * Cart operations for the shopping flow.
 *
 * Reference: https://ucp.dev/specification/overview/
 */
interface CartCapabilityInterface extends CapabilityInterface
{
    public function createCart(CartCreateRequest $request, RequestContext $context): Cart;

    public function getCart(string $id, RequestContext $context): Cart;

    public function updateCart(CartUpdateRequest $request, RequestContext $context): Cart;

    public function cancelCart(string $id, RequestContext $context): Cart;
}
