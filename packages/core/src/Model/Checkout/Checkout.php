<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Checkout;

use Ucp\Sdk\Enum\CheckoutStatus;
use Ucp\Sdk\Model\Common\Buyer;
use Ucp\Sdk\Model\Common\LineItem;
use Ucp\Sdk\Model\Common\Link;
use Ucp\Sdk\Model\Common\Message;
use Ucp\Sdk\Model\Common\Money;

final readonly class Checkout
{
    /**
     * @param list<LineItem> $lineItems
     * @param list<Money> $totals
     * @param list<Message> $messages
     * @param list<Link> $links
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public string $id,
        public CheckoutStatus $status,
        public string $currency,
        public array $lineItems,
        public array $totals,
        public array $messages = [],
        public array $links = [],
        public ?Buyer $buyer = null,
        public ?string $continueUrl = null,
        public ?string $expiresAt = null,
        public ?OrderConfirmation $order = null,
        public array $extra = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'status' => $this->status->value,
            'currency' => $this->currency,
            'line_items' => array_map(static fn (LineItem $item): array => $item->toArray(), $this->lineItems),
            'totals' => array_map(static fn (Money $money): array => $money->toArray(), $this->totals),
            'messages' => array_map(static fn (Message $message): array => $message->toArray(), $this->messages),
            'links' => array_map(static fn (Link $link): array => $link->toArray(), $this->links),
        ];

        if ($this->buyer !== null) {
            $data['buyer'] = $this->buyer->toArray();
        }

        if ($this->continueUrl !== null) {
            $data['continue_url'] = $this->continueUrl;
        }

        if ($this->expiresAt !== null) {
            $data['expires_at'] = $this->expiresAt;
        }

        if ($this->order !== null) {
            $data['order'] = $this->order->toArray();
        }

        return array_merge($data, $this->extra);
    }
}
