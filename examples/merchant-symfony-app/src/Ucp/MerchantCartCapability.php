<?php

declare(strict_types=1);

namespace MerchantSymfonyApp\Ucp;

use MerchantSymfonyApp\Support\JsonStateStore;
use MerchantSymfonyApp\Support\MerchantSettings;
use MerchantSymfonyApp\Support\PriceCalculator;
use MerchantSymfonyApp\Support\UcpModelFactory;
use Ucp\Sdk\Contract\CartCapabilityInterface;
use Ucp\Sdk\Exception\ResourceNotFoundException;
use Ucp\Sdk\Model\Cart\Cart;
use Ucp\Sdk\Model\Cart\CartCreateRequest;
use Ucp\Sdk\Model\Cart\CartUpdateRequest;
use Ucp\Sdk\Model\Common\Message;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\RequestContext;

final class MerchantCartCapability implements CartCapabilityInterface
{
    private const COLLECTION = 'merchant_carts';

    public function __construct(
        private readonly JsonStateStore $stateStore,
        private readonly PriceCalculator $priceCalculator,
        private readonly UcpModelFactory $modelFactory,
        private readonly MerchantSettings $settings,
    ) {
    }

    public function describe(): CapabilityDescriptor
    {
        return new CapabilityDescriptor(
            'dev.ucp.shopping.cart',
            '2026-04-08',
            'https://ucp.dev/specification/overview/',
            'https://ucp.dev/schemas/shopping/cart.json',
            null,
            [
                'persistence' => 'json-file',
                'currency' => $this->settings->currency,
            ],
        );
    }

    public function createCart(CartCreateRequest $request, RequestContext $context): Cart
    {
        $lineItems = $this->priceCalculator->canonicalizeLineItems($request->lineItems);

        $cart = new Cart(
            $this->generateId('cart'),
            $lineItems,
            $this->settings->currency,
            $this->priceCalculator->calculateTotals($lineItems),
            [new Message('info', 'Cart created for merchant example.')],
        );

        $this->stateStore->put(self::COLLECTION, $cart->id, $cart->toArray());

        return $cart;
    }

    public function getCart(string $id, RequestContext $context): Cart
    {
        $record = $this->stateStore->find(self::COLLECTION, $id);
        if ($record === null) {
            // Not an empty cart carrying a warning. That answer is a cart -- an agent that
            // reads the status and not the messages adds items to a cart the business does
            // not have -- and it does not even validate, because a fabricated cart has no
            // totals, so the caller received `invalid_request` about our own response rather
            // than `not_found` about their id.
            throw new ResourceNotFoundException(sprintf('Cart "%s" was not found.', $id));
        }

        return $this->modelFactory->cartFromArray($record);
    }

    public function updateCart(CartUpdateRequest $request, RequestContext $context): Cart
    {
        $lineItems = $this->priceCalculator->canonicalizeLineItems($request->lineItems);
        $cart = new Cart(
            $request->id,
            $lineItems,
            $this->settings->currency,
            $this->priceCalculator->calculateTotals($lineItems),
            [new Message('info', 'Cart updated from merchant catalog.')],
        );

        $this->stateStore->put(self::COLLECTION, $cart->id, $cart->toArray());

        return $cart;
    }

    public function cancelCart(string $id, RequestContext $context): Cart
    {
        $cart = $this->getCart($id, $context);
        $this->stateStore->remove(self::COLLECTION, $id);

        return new Cart(
            $cart->id,
            $cart->lineItems,
            $cart->currency,
            $cart->totals,
            array_merge($cart->messages, [new Message('info', 'Cart canceled and removed from merchant state.')]),
        );
    }

    private function generateId(string $prefix): string
    {
        return $prefix . '_' . bin2hex(random_bytes(6));
    }
}
