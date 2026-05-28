<?php

declare(strict_types=1);

namespace MerchantSymfonyApp\Ucp;

use MerchantSymfonyApp\Support\JsonStateStore;
use MerchantSymfonyApp\Support\MerchantSettings;
use MerchantSymfonyApp\Support\PriceCalculator;
use MerchantSymfonyApp\Support\UcpModelFactory;
use Ucp\Sdk\Contract\CheckoutCapabilityInterface;
use Ucp\Sdk\Enum\CheckoutStatus;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Checkout\CheckoutCreateRequest;
use Ucp\Sdk\Model\Checkout\CheckoutUpdateRequest;
use Ucp\Sdk\Model\Checkout\OrderConfirmation;
use Ucp\Sdk\Model\Common\Link;
use Ucp\Sdk\Model\Common\Message;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\RequestContext;

final readonly class MerchantCheckoutCapability implements CheckoutCapabilityInterface
{
    private const COLLECTION = 'merchant_checkouts';
    private const ORDER_COLLECTION = 'merchant_orders';

    public function __construct(
        private JsonStateStore $stateStore,
        private PriceCalculator $priceCalculator,
        private UcpModelFactory $modelFactory,
        private MerchantSettings $settings,
    ) {
    }

    public function describe(): CapabilityDescriptor
    {
        return new CapabilityDescriptor(
            'dev.ucp.shopping.checkout',
            '2026-04-08',
            'https://ucp.dev/specification/checkout/',
            'https://ucp.dev/schemas/shopping/checkout.json',
            null,
            [
                'country' => $this->settings->country,
                'fulfillment_methods' => ['standard-shipping', 'express-shipping', 'pickup-store'],
            ],
        );
    }

    public function createCheckout(CheckoutCreateRequest $request, RequestContext $context): Checkout
    {
        $checkout = $this->checkoutFromRequest(
            $this->generateId('chk'),
            $request->lineItems,
            $request->discounts,
            $request->fulfillment,
            $request->buyer,
            CheckoutStatus::Incomplete,
            [new Message('info', 'Checkout created from merchant example.')],
        );

        $this->stateStore->put(self::COLLECTION, $checkout->id, $checkout->toArray());

        return $checkout;
    }

    public function getCheckout(string $id, RequestContext $context): Checkout
    {
        $record = $this->stateStore->find(self::COLLECTION, $id);
        if ($record === null) {
            return new Checkout(
                $id,
                CheckoutStatus::Incomplete,
                $this->settings->currency,
                [],
                [],
                [new Message('error', 'Checkout not found.', 'warning', 'checkout_not_found')],
            );
        }

        return $this->modelFactory->checkoutFromArray($record);
    }

    public function updateCheckout(CheckoutUpdateRequest $request, RequestContext $context): Checkout
    {
        $status = $request->payment !== null && $request->buyer !== null
            ? CheckoutStatus::ReadyForComplete
            : CheckoutStatus::Incomplete;

        $checkout = $this->checkoutFromRequest(
            $request->id,
            $request->lineItems,
            $request->discounts,
            $request->fulfillment,
            $request->buyer,
            $status,
            [new Message('info', 'Checkout updated and re-priced.')],
        );

        $this->stateStore->put(self::COLLECTION, $checkout->id, $checkout->toArray());

        return $checkout;
    }

    public function completeCheckout(string $id, RequestContext $context): Checkout
    {
        $checkout = $this->getCheckout($id, $context);
        $orderId = 'ord_' . substr($checkout->id, 4);

        $completed = new Checkout(
            $checkout->id,
            CheckoutStatus::Completed,
            $checkout->currency,
            $checkout->lineItems,
            $checkout->totals,
            array_merge($checkout->messages, [new Message('info', 'Checkout completed and converted into an order.')]),
            $checkout->links,
            $checkout->buyer,
            $checkout->continueUrl,
            $checkout->expiresAt,
            new OrderConfirmation($orderId, $this->settings->orderPermalink($orderId)),
            $checkout->extra,
        );

        $this->stateStore->put(self::ORDER_COLLECTION, $orderId, [
            'id' => $orderId,
            'currency' => $completed->currency,
            'line_items' => array_map(static fn ($item): array => $item->toArray(), $completed->lineItems),
            'totals' => array_map(static fn ($money): array => $money->toArray(), $completed->totals),
            'messages' => array_map(static fn ($message): array => $message->toArray(), $completed->messages),
            'links' => array_map(static fn ($link): array => $link->toArray(), [
                ...$completed->links,
                new Link('order', $this->settings->orderPermalink($orderId), 'Order details'),
            ]),
            'buyer' => $completed->buyer?->toArray(),
            'created_at' => gmdate('c'),
            'merchant_reference' => $completed->extra['merchant_reference'] ?? [],
        ]);
        $this->stateStore->put(self::COLLECTION, $completed->id, $completed->toArray());

        return $completed;
    }

    public function cancelCheckout(string $id, RequestContext $context): Checkout
    {
        $checkout = $this->getCheckout($id, $context);

        $canceled = new Checkout(
            $checkout->id,
            CheckoutStatus::Canceled,
            $checkout->currency,
            $checkout->lineItems,
            $checkout->totals,
            array_merge($checkout->messages, [new Message('info', 'Checkout canceled by merchant.')]),
            $checkout->links,
            $checkout->buyer,
            $checkout->continueUrl,
            $checkout->expiresAt,
            $checkout->order,
            $checkout->extra,
        );

        $this->stateStore->put(self::COLLECTION, $canceled->id, $canceled->toArray());

        return $canceled;
    }

    /**
     * @param list<\Ucp\Sdk\Model\Common\LineItem> $lineItems
     * @param list<\Ucp\Sdk\Model\Checkout\DiscountCode> $discounts
     * @param list<Message> $messages
     */
    private function checkoutFromRequest(
        string $id,
        array $lineItems,
        array $discounts,
        ?\Ucp\Sdk\Model\Checkout\FulfillmentSelection $fulfillment,
        ?\Ucp\Sdk\Model\Common\Buyer $buyer,
        CheckoutStatus $status,
        array $messages,
    ): Checkout {
        $canonicalLineItems = $this->priceCalculator->canonicalizeLineItems($lineItems);

        return new Checkout(
            $id,
            $status,
            $this->settings->currency,
            $canonicalLineItems,
            $this->priceCalculator->calculateTotals($canonicalLineItems, $discounts, $fulfillment),
            $messages,
            [
                new Link('privacy', $this->settings->baseUri . '/privacy', 'Privacy policy'),
                new Link('terms', $this->settings->baseUri . '/terms', 'Terms and conditions'),
            ],
            $buyer,
            $this->settings->checkoutContinueUrl($id),
            gmdate('c', time() + 1800),
            null,
            [
                'merchant_reference' => [
                    'brand' => $this->settings->brandName,
                    'fulfillment_method' => $fulfillment?->methodId,
                    'discount_codes' => array_map(static fn (\Ucp\Sdk\Model\Checkout\DiscountCode $discount): string => $discount->code, $discounts),
                ],
            ],
        );
    }

    private function generateId(string $prefix): string
    {
        return $prefix . '_' . bin2hex(random_bytes(6));
    }
}
