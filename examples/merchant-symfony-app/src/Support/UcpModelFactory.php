<?php

declare(strict_types=1);

namespace MerchantSymfonyApp\Support;

use Ucp\Sdk\Enum\CheckoutStatus;
use Ucp\Sdk\Model\Cart\Cart;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Checkout\OrderConfirmation;
use Ucp\Sdk\Model\Common\Buyer;
use Ucp\Sdk\Model\Common\LineItem;
use Ucp\Sdk\Model\Common\Link;
use Ucp\Sdk\Model\Common\Message;
use Ucp\Sdk\Model\Common\MonetaryAmount;
use Ucp\Sdk\Model\Common\Money;
use Ucp\Sdk\Model\Order\OrderView;

final class UcpModelFactory
{
    /**
     * @param array<string, mixed> $payload
     */
    public function cartFromArray(array $payload): Cart
    {
        return new Cart(
            (string) ($payload['id'] ?? ''),
            $this->lineItems($payload['line_items'] ?? []),
            (string) ($payload['currency'] ?? 'EUR'),
            $this->totals($payload['totals'] ?? []),
            $this->messages($payload['messages'] ?? []),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function checkoutFromArray(array $payload): Checkout
    {
        $knownKeys = [
            'id',
            'status',
            'currency',
            'line_items',
            'totals',
            'messages',
            'links',
            'buyer',
            'continue_url',
            'expires_at',
            'order',
        ];

        /** @var array<string, mixed> $extra */
        $extra = array_diff_key($payload, array_flip($knownKeys));

        return new Checkout(
            (string) ($payload['id'] ?? ''),
            CheckoutStatus::from((string) ($payload['status'] ?? CheckoutStatus::Incomplete->value)),
            (string) ($payload['currency'] ?? 'EUR'),
            $this->lineItems($payload['line_items'] ?? []),
            $this->totals($payload['totals'] ?? []),
            $this->messages($payload['messages'] ?? []),
            $this->links($payload['links'] ?? []),
            $this->buyer($payload['buyer'] ?? null),
            is_string($payload['continue_url'] ?? null) ? $payload['continue_url'] : null,
            is_string($payload['expires_at'] ?? null) ? $payload['expires_at'] : null,
            $this->order($payload['order'] ?? null),
            $extra,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function orderFromArray(array $payload): OrderView
    {
        $knownKeys = [
            'id',
            'currency',
            'line_items',
            'totals',
            'messages',
            'links',
            'buyer',
            'created_at',
            'checkout_id',
            'permalink_url',
            'fulfillment',
        ];

        /** @var array<string, mixed> $extra */
        $extra = array_diff_key($payload, array_flip($knownKeys));

        return new OrderView(
            (string) ($payload['id'] ?? ''),
            (string) ($payload['currency'] ?? 'EUR'),
            $this->lineItems($payload['line_items'] ?? []),
            $this->totals($payload['totals'] ?? []),
            $this->messages($payload['messages'] ?? []),
            $this->links($payload['links'] ?? []),
            $this->buyer($payload['buyer'] ?? null),
            is_string($payload['created_at'] ?? null) ? $payload['created_at'] : null,
            $extra,
            is_string($payload['checkout_id'] ?? null) ? $payload['checkout_id'] : null,
            is_string($payload['permalink_url'] ?? null) ? $payload['permalink_url'] : null,
            is_array($payload['fulfillment'] ?? null) ? $payload['fulfillment'] : null,
        );
    }

    /**
     * Undoes the minor-unit encoding LineItem::toArray() applies.
     *
     * This app persists the wire payload, and `LineItem::toArray()` writes `item.price` in
     * minor units while `LineItem::__construct()` takes major ones. Reading the value straight
     * back therefore multiplied every price by the currency scale -- a 249.00 tent rehydrated
     * as 24,900.00 -- so every total derived from a stored cart was inflated a hundredfold.
     * Nothing caught it because the only assertion on a discounted total was that it is
     * negative.
     *
     * The scale is derived rather than hardcoded: MonetaryAmount knows each currency's
     * exponent, and one major unit expressed in minor units is exactly that scale (100 for EUR,
     * 1 for JPY). The SDK exposes no minor-to-major conversion, which is the asymmetry behind
     * this bug and is worth fixing there rather than here.
     */
    private static function majorUnits(float $minorUnits): float
    {
        $scale = MonetaryAmount::fromMajorUnits(1.0, 'EUR')->minorUnits;

        return $scale > 0 ? $minorUnits / $scale : $minorUnits;
    }

    /**
     * @return list<LineItem>
     */
    private function lineItems(mixed $payload): array
    {
        $lineItems = [];

        foreach (is_array($payload) ? $payload : [] as $lineItem) {
            if (! is_array($lineItem)) {
                continue;
            }

            $item = is_array($lineItem['item'] ?? null) ? $lineItem['item'] : [];

            /** @var array<string, mixed> $extra */
            $extra = array_diff_key($lineItem, ['item' => true, 'quantity' => true]);

            $lineItems[] = new LineItem(
                (string) ($item['id'] ?? ''),
                (string) ($item['title'] ?? ''),
                self::majorUnits((float) ($item['price'] ?? 0.0)),
                (int) ($lineItem['quantity'] ?? 1),
                is_string($item['image_url'] ?? null) ? $item['image_url'] : null,
                $extra,
            );
        }

        return $lineItems;
    }

    /**
     * @param mixed $payload
     * @return list<Money>
     */
    private function totals(mixed $payload): array
    {
        $totals = [];

        foreach (is_array($payload) ? $payload : [] as $money) {
            if (! is_array($money)) {
                continue;
            }

            $totals[] = new Money(
                (string) ($money['type'] ?? ''),
                (float) ($money['amount'] ?? 0.0),
                is_string($money['display_text'] ?? null) ? $money['display_text'] : null,
            );
        }

        return $totals;
    }

    /**
     * @param mixed $payload
     * @return list<Message>
     */
    private function messages(mixed $payload): array
    {
        $messages = [];

        foreach (is_array($payload) ? $payload : [] as $message) {
            if (! is_array($message)) {
                continue;
            }

            $messages[] = new Message(
                (string) ($message['type'] ?? 'info'),
                (string) ($message['content'] ?? ''),
                is_string($message['severity'] ?? null) ? $message['severity'] : null,
                is_string($message['code'] ?? null) ? $message['code'] : null,
                is_string($message['path'] ?? null) ? $message['path'] : null,
            );
        }

        return $messages;
    }

    /**
     * @param mixed $payload
     * @return list<Link>
     */
    private function links(mixed $payload): array
    {
        $links = [];

        foreach (is_array($payload) ? $payload : [] as $link) {
            if (! is_array($link)) {
                continue;
            }

            $links[] = new Link(
                (string) ($link['type'] ?? ''),
                (string) ($link['url'] ?? ''),
                is_string($link['title'] ?? null) ? $link['title'] : null,
            );
        }

        return $links;
    }

    private function buyer(mixed $payload): ?Buyer
    {
        if (! is_array($payload)) {
            return null;
        }

        return new Buyer(
            is_string($payload['email'] ?? null) ? $payload['email'] : null,
            is_string($payload['first_name'] ?? null) ? $payload['first_name'] : null,
            is_string($payload['last_name'] ?? null) ? $payload['last_name'] : null,
            is_string($payload['phone_number'] ?? null) ? $payload['phone_number'] : null,
        );
    }

    private function order(mixed $payload): ?OrderConfirmation
    {
        if (! is_array($payload)) {
            return null;
        }

        return new OrderConfirmation(
            (string) ($payload['id'] ?? ''),
            is_string($payload['permalink_url'] ?? null) ? $payload['permalink_url'] : null,
        );
    }
}
