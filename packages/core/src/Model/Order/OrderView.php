<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Order;

use Ucp\Sdk\Model\Common\Buyer;
use Ucp\Sdk\Model\Common\LineItem;
use Ucp\Sdk\Model\Common\Link;
use Ucp\Sdk\Model\Common\Message;
use Ucp\Sdk\Model\Common\Money;

final class OrderView
{
    /**
     * @param list<LineItem> $lineItems
     * @param list<Money> $totals
     * @param list<Message> $messages
     * @param list<Link> $links
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public readonly string $id,
        public readonly string $currency,
        public readonly array $lineItems,
        public readonly array $totals,
        public readonly array $messages = [],
        public readonly array $links = [],
        public readonly ?Buyer $buyer = null,
        public readonly ?string $createdAt = null,
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
            'currency' => $this->currency,
            'line_items' => array_map(static fn (LineItem $item): array => $item->toArray(), $this->lineItems),
            'totals' => array_map(static fn (Money $money): array => $money->toArray(), $this->totals),
            'messages' => array_map(static fn (Message $message): array => $message->toArray(), $this->messages),
            'links' => array_map(static fn (Link $link): array => $link->toArray(), $this->links),
        ];

        if ($this->buyer !== null) {
            $data['buyer'] = $this->buyer->toArray();
        }

        if ($this->createdAt !== null) {
            $data['created_at'] = $this->createdAt;
        }

        return array_merge($data, $this->extra);
    }
}
