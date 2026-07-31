<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Cart;

use Ucp\Sdk\Model\Common\LineItem;
use Ucp\Sdk\Model\Common\Message;
use Ucp\Sdk\Model\Common\Money;
use Ucp\Sdk\Model\Protocol\UcpOperationPayload;

final class Cart implements UcpOperationPayload
{
    /**
     * @param list<LineItem> $lineItems
     * @param list<Money> $totals
     * @param list<Message> $messages
     * @param array<string, mixed> $extra fields contributed by capability extensions, merged into the payload
     */
    public function __construct(
        public readonly string $id,
        public readonly array $lineItems,
        public readonly string $currency,
        public readonly array $totals = [],
        public readonly array $messages = [],
        public readonly array $extra = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'line_items' => array_map(static fn (LineItem $item): array => $item->toArray(), $this->lineItems),
            'currency' => $this->currency,
            'totals' => array_map(static fn (Money $money): array => $money->toArray(), $this->totals),
            'messages' => array_map(static fn (Message $message): array => $message->toArray(), $this->messages),
        ];

        // Cart was the only shopping payload without this, so a capability that
        // extends the cart had nowhere to put its fields: `discount.json` adds
        // `discounts` (including the `applied` breakdown), and cart.get, cart.update
        // and discount.apply all answer with a Cart. Checkout and OrderView have
        // merged `extra` since they were written; this makes Cart consistent.
        return array_merge($data, $this->extra);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
