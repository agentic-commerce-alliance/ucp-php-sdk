<?php

declare(strict_types=1);

namespace Ucp\Sdk\Adapter\Model;

use Ucp\Sdk\Enum\CheckoutStatus;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Checkout\OrderConfirmation;
use Ucp\Sdk\Model\Common\LineItem;
use Ucp\Sdk\Model\Common\Link;
use Ucp\Sdk\Model\Common\Message;
use Ucp\Sdk\Model\Common\Money;

final readonly class CheckoutRecord
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
        public ?BuyerRecord $buyer = null,
        public ?string $continueUrl = null,
        public ?string $expiresAt = null,
        public ?OrderConfirmation $order = null,
        public array $extra = [],
    ) {
    }

    public function toCheckout(): Checkout
    {
        return new Checkout(
            $this->id,
            $this->status,
            $this->currency,
            $this->lineItems,
            $this->totals,
            $this->messages,
            $this->links,
            $this->buyer?->toBuyer(),
            $this->continueUrl,
            $this->expiresAt,
            $this->order,
            $this->extra,
        );
    }
}
