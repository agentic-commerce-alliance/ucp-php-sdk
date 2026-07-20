<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Checkout;

use Ucp\Sdk\Enum\CheckoutStatus;
use Ucp\Sdk\Model\Common\Buyer;
use Ucp\Sdk\Model\Common\LineItem;
use Ucp\Sdk\Model\Common\Link;
use Ucp\Sdk\Model\Common\Message;
use Ucp\Sdk\Model\Common\Money;
use Ucp\Sdk\Model\Protocol\UcpOperationPayload;

final class Checkout implements UcpOperationPayload
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
        public readonly CheckoutStatus $status,
        public readonly string $currency,
        public readonly array $lineItems,
        public readonly array $totals,
        public readonly array $messages = [],
        public readonly array $links = [],
        public readonly ?Buyer $buyer = null,
        public readonly ?string $continueUrl = null,
        public readonly ?string $expiresAt = null,
        public readonly ?OrderConfirmation $order = null,
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

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * A deterministic fingerprint of the economic terms an AP2 mandate authorizes: currency,
     * line items, and totals. AP2 verifiers approve a checkout snapshot, and completion adapters
     * compare this fingerprint against the stored state to refuse completing terms that changed
     * after verification (the verification-to-completion race).
     */
    public function termsFingerprint(): string
    {
        return hash('sha256', (string) json_encode([
            'currency' => $this->currency,
            'line_items' => array_map(static fn (LineItem $item): array => $item->toArray(), $this->lineItems),
            'totals' => array_map(static fn (Money $money): array => $money->toArray(), $this->totals),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function withExtra(array $extra): self
    {
        return new self(
            $this->id,
            $this->status,
            $this->currency,
            $this->lineItems,
            $this->totals,
            $this->messages,
            $this->links,
            $this->buyer,
            $this->continueUrl,
            $this->expiresAt,
            $this->order,
            array_merge($this->extra, $extra),
        );
    }
}
