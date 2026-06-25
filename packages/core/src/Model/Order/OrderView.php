<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Order;

use Ucp\Sdk\Model\Common\Buyer;
use Ucp\Sdk\Model\Common\LineItem;
use Ucp\Sdk\Model\Common\Link;
use Ucp\Sdk\Model\Common\Message;
use Ucp\Sdk\Model\Common\Money;
use Ucp\Sdk\Model\Protocol\UcpOperationPayload;

final class OrderView implements UcpOperationPayload
{
    /**
     * @param list<LineItem> $lineItems
     * @param list<Money> $totals
     * @param list<Message> $messages
     * @param list<Link> $links
     * @param array<string, bool|float|int|string|null|array<string, bool|float|int|string|null>|list<bool|float|int|string|null>> $extra
     * @param array<string, bool|float|int|string|null|array<string, bool|float|int|string|null>|list<bool|float|int|string|null>>|null $fulfillment
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
        public readonly ?string $checkoutId = null,
        public readonly ?string $permalinkUrl = null,
        public readonly ?array $fulfillment = null,
    ) {
    }

    /**
     * @return array{
     *     id: string,
     *     currency: string,
     *     line_items: list<array{
     *         id: string,
     *         item: array{id: string, title: string, price: int, image_url?: string},
     *         quantity: array{original: int, total: int, fulfilled: int},
     *         totals: list<array{type: string, amount: int}>,
     *         status: string
     *     }>,
     *     totals: list<array<string, int|string>>,
     *     messages: list<array<string, string|null>>,
     *     links: list<array<string, string>>,
     *     buyer?: array<string, string>,
     *     created_at?: string,
     *     checkout_id?: string,
     *     permalink_url?: string,
     *     fulfillment?: array<string, bool|float|int|string|null|array<string, bool|float|int|string|null>|list<bool|float|int|string|null>>
     * }
     */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'currency' => $this->currency,
            'line_items' => array_map(static function (LineItem $item): array {
                $lineItem = $item->toArray();
                $lineItem['quantity'] = [
                    'original' => $item->quantity,
                    'total' => $item->quantity,
                    'fulfilled' => 0,
                ];
                $lineItem['status'] = 'processing';

                /** @var array{id: string, item: array{id: string, title: string, price: int, image_url?: string}, quantity: array{original: int, total: int, fulfilled: int}, totals: list<array{type: string, amount: int}>, status: string} $lineItem */
                return $lineItem;
            }, $this->lineItems),
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

        if ($this->checkoutId !== null) {
            $data['checkout_id'] = $this->checkoutId;
        }

        if ($this->permalinkUrl !== null) {
            $data['permalink_url'] = $this->permalinkUrl;
        }

        if ($this->fulfillment !== null) {
            $data['fulfillment'] = $this->fulfillment;
        }

        /** @var array{id: string, currency: string, line_items: list<array{id: string, item: array{id: string, title: string, price: int, image_url?: string}, quantity: array{original: int, total: int, fulfilled: int}, totals: list<array{type: string, amount: int}>, status: string}>, totals: list<array<string, int|string>>, messages: list<array<string, string|null>>, links: list<array<string, string>>, buyer?: array<string, string>, created_at?: string, checkout_id?: string, permalink_url?: string, fulfillment?: array<string, bool|float|int|string|null|array<string, bool|float|int|string|null>|list<bool|float|int|string|null>>} $payload */
        $payload = array_merge($data, $this->extra);

        return $payload;
    }

    /**
     * @return array{
     *     id: string,
     *     currency: string,
     *     line_items: list<array{
     *         id: string,
     *         item: array{id: string, title: string, price: int, image_url?: string},
     *         quantity: array{original: int, total: int, fulfilled: int},
     *         totals: list<array{type: string, amount: int}>,
     *         status: string
     *     }>,
     *     totals: list<array<string, int|string>>,
     *     messages: list<array<string, string|null>>,
     *     links: list<array<string, string>>,
     *     buyer?: array<string, string>,
     *     created_at?: string,
     *     checkout_id?: string,
     *     permalink_url?: string,
     *     fulfillment?: array<string, bool|float|int|string|null|array<string, bool|float|int|string|null>|list<bool|float|int|string|null>>
     * }
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
