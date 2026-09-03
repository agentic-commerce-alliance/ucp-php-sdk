<?php

declare(strict_types=1);

namespace MerchantSymfonyApp\Ucp;

use MerchantSymfonyApp\Support\FulfillmentPlanner;
use MerchantSymfonyApp\Support\JsonStateStore;
use MerchantSymfonyApp\Support\MerchantSettings;
use MerchantSymfonyApp\Support\PriceCalculator;
use MerchantSymfonyApp\Support\UcpModelFactory;
use Ucp\Sdk\Contract\CheckoutCapabilityInterface;
use Ucp\Sdk\Enum\CheckoutStatus;
use Ucp\Sdk\Enum\UcpProtocolVersion;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Checkout\CheckoutCreateRequest;
use Ucp\Sdk\Model\Checkout\CheckoutUpdateRequest;
use Ucp\Sdk\Model\Checkout\OrderConfirmation;
use Ucp\Sdk\Model\Common\Link;
use Ucp\Sdk\Model\Common\Message;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\RequestContext;

final class MerchantCheckoutCapability implements CheckoutCapabilityInterface
{
    private const COLLECTION = 'merchant_checkouts';
    private const CART_COLLECTION = 'merchant_carts';
    private const ORDER_COLLECTION = 'merchant_orders';

    public function __construct(
        private readonly JsonStateStore $stateStore,
        private readonly PriceCalculator $priceCalculator,
        private readonly UcpModelFactory $modelFactory,
        private readonly MerchantSettings $settings,
        private readonly FulfillmentPlanner $fulfillmentPlanner = new FulfillmentPlanner(),
    ) {
    }

    public function describe(): CapabilityDescriptor
    {
        return new CapabilityDescriptor(
            'dev.ucp.shopping.checkout',
            UcpProtocolVersion::current()->value,
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
        $lineItems = $request->lineItems;
        $checkoutId = $this->generateId('chk');
        if ([] === $lineItems && null !== $request->cartId) {
            $checkoutId = $request->cartId;
            $cart = $this->stateStore->find(self::CART_COLLECTION, $request->cartId);
            $lineItems = null !== $cart ? $this->modelFactory->cartFromArray($cart)->lineItems : [];
        }

        $checkout = $this->checkoutFromRequest(
            $checkoutId,
            $lineItems,
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

        // Completing a cancelled checkout would mint an order against a session the buyer or
        // the business already withdrew, and it reads as success to the caller.
        if ($checkout->status === CheckoutStatus::Canceled) {
            throw new ValidationException('This checkout was canceled and can no longer be completed.', [
                sprintf('Checkout "%s" is in status "canceled".', $checkout->id),
            ]);
        }

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
            'checkout_id' => $completed->id,
            'permalink_url' => $this->settings->orderPermalink($orderId),
            'currency' => $completed->currency,
            'line_items' => array_map(static fn ($item): array => $item->toArray(), $completed->lineItems),
            'fulfillment' => [],
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
        $plannedFulfillment = $this->fulfillmentPlanner->plan($fulfillment, $canonicalLineItems);

        return new Checkout(
            $id,
            $status,
            $this->settings->currency,
            $canonicalLineItems,
            $this->priceCalculator->calculateTotals($canonicalLineItems, $discounts, $plannedFulfillment),
            $messages,
            [
                new Link('privacy', $this->settings->baseUri . '/privacy', 'Privacy policy'),
                new Link('terms', $this->settings->baseUri . '/terms', 'Terms and conditions'),
            ],
            $buyer,
            $this->settings->checkoutContinueUrl($id),
            gmdate('c', time() + 1800),
            null,
            array_filter([
                // Checkout::toArray() merges `extra` at the top level, which is where the
                // fulfillment extension belongs on the wire.
                'fulfillment' => $plannedFulfillment,
                'merchant_reference' => [
                    'brand' => $this->settings->brandName,
                    'fulfillment_method' => $fulfillment?->methodId,
                    'discount_codes' => array_map(static fn (\Ucp\Sdk\Model\Checkout\DiscountCode $discount): string => $discount->code, $discounts),
                ],
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    private function generateId(string $prefix): string
    {
        return $prefix . '_' . bin2hex(random_bytes(6));
    }
}
