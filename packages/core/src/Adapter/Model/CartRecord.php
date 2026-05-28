<?php

declare(strict_types=1);

namespace Ucp\Sdk\Adapter\Model;

use Ucp\Sdk\Model\Cart\Cart;
use Ucp\Sdk\Model\Common\LineItem;
use Ucp\Sdk\Model\Common\Message;
use Ucp\Sdk\Model\Common\Money;

final readonly class CartRecord
{
    /**
     * @param list<LineItem> $lineItems
     * @param list<Money> $totals
     * @param list<Message> $messages
     */
    public function __construct(
        public string $id,
        public array $lineItems,
        public string $currency,
        public array $totals = [],
        public array $messages = [],
    ) {
    }

    public function toCart(): Cart
    {
        return new Cart($this->id, $this->lineItems, $this->currency, $this->totals, $this->messages);
    }
}
