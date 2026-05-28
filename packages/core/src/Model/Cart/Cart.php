<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Cart;

use Ucp\Sdk\Model\Common\LineItem;
use Ucp\Sdk\Model\Common\Message;
use Ucp\Sdk\Model\Common\Money;

final readonly class Cart
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

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'line_items' => array_map(static fn (LineItem $item): array => $item->toArray(), $this->lineItems),
            'currency' => $this->currency,
            'totals' => array_map(static fn (Money $money): array => $money->toArray(), $this->totals),
            'messages' => array_map(static fn (Message $message): array => $message->toArray(), $this->messages),
        ];
    }
}
