<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Cart;

use Ucp\Sdk\Model\Common\LineItem;

final readonly class CartUpdateRequest
{
    /**
     * @param list<LineItem> $lineItems
     */
    public function __construct(
        public string $id,
        public array $lineItems,
    ) {
    }
}
