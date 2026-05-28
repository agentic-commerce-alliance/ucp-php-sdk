<?php

declare(strict_types=1);

namespace Ucp\Sdk\Adapter\Model;

use Ucp\Sdk\Model\Common\LineItem;
use Ucp\Sdk\Model\Common\Link;
use Ucp\Sdk\Model\Common\Message;
use Ucp\Sdk\Model\Common\Money;
use Ucp\Sdk\Model\Order\OrderView;

final readonly class OrderRecord
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
        public string $currency,
        public array $lineItems,
        public array $totals,
        public array $messages = [],
        public array $links = [],
        public ?BuyerRecord $buyer = null,
        public ?string $createdAt = null,
        public array $extra = [],
    ) {
    }

    public function toOrderView(): OrderView
    {
        return new OrderView(
            $this->id,
            $this->currency,
            $this->lineItems,
            $this->totals,
            $this->messages,
            $this->links,
            $this->buyer?->toBuyer(),
            $this->createdAt,
            $this->extra,
        );
    }
}
