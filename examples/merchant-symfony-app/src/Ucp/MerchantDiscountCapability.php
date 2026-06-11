<?php

declare(strict_types=1);

namespace MerchantSymfonyApp\Ucp;

use MerchantSymfonyApp\Support\JsonStateStore;
use MerchantSymfonyApp\Support\MerchantSettings;
use MerchantSymfonyApp\Support\PriceCalculator;
use MerchantSymfonyApp\Support\UcpModelFactory;
use Ucp\Sdk\Contract\DiscountCapabilityInterface;
use Ucp\Sdk\Model\Cart\Cart;
use Ucp\Sdk\Model\Checkout\DiscountCode;
use Ucp\Sdk\Model\Common\Message;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\RequestContext;

final class MerchantDiscountCapability implements DiscountCapabilityInterface
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
            'dev.ucp.shopping.discount',
            '2026-04-08',
            'https://ucp.dev/specification/overview/',
            'https://ucp.dev/schemas/shopping/discount.json',
            ['dev.ucp.shopping.cart'],
            [
                'codes' => ['SAVE10'],
                'currency' => $this->settings->currency,
            ],
        );
    }

    public function applyCartDiscount(string $cartId, DiscountCode $discount, RequestContext $context): Cart
    {
        $record = $this->stateStore->find(self::COLLECTION, $cartId);
        if ($record === null) {
            return new Cart($cartId, [], $this->settings->currency, [], [
                new Message('error', 'Cart not found.', 'warning', 'cart_not_found'),
            ]);
        }

        $cart = $this->modelFactory->cartFromArray($record);
        $updated = new Cart(
            $cart->id,
            $cart->lineItems,
            $cart->currency,
            $this->priceCalculator->calculateTotals($cart->lineItems, [$discount]),
            array_merge($cart->messages, [new Message('info', sprintf('Discount code %s applied.', $discount->code))]),
        );

        $this->stateStore->put(self::COLLECTION, $updated->id, $updated->toArray());

        return $updated;
    }
}
