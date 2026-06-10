<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Cart;

use Ucp\Sdk\Model\Common\LineItem;
use Ucp\Sdk\Model\Common\Signals;

final class CartCreateRequest
{
    /**
     * @param list<LineItem> $lineItems
     */
    public function __construct(
        public readonly array $lineItems,
        public readonly ?Signals $signals = null,
    ) {
    }
}
