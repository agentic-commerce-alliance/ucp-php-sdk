<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Operation;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Ucp\Sdk\Contract\CartCapabilityInterface;
use Ucp\Sdk\Contract\CatalogCapabilityInterface;
use Ucp\Sdk\Contract\CheckoutCapabilityInterface;
use Ucp\Sdk\Contract\CheckoutRequestValidatorInterface;
use Ucp\Sdk\Contract\CheckoutResponseAugmenterInterface;
use Ucp\Sdk\Contract\DiscountCapabilityInterface;
use Ucp\Sdk\Contract\OrderCapabilityInterface;
use Ucp\Sdk\Contract\PaymentMandateVerifierInterface;
use Ucp\Sdk\Event\CheckoutRequestReceivedEvent;
use Ucp\Sdk\Event\CheckoutResponsePreparedEvent;
use Ucp\Sdk\Event\PaymentMandateVerificationEvent;
use Ucp\Sdk\Exception\UnsupportedCapabilityException;
use Ucp\Sdk\Model\Catalog\Product;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Checkout\DiscountCode;
use Ucp\Sdk\Service\CapabilityRegistryInterface;
use Ucp\Sdk\Service\ProtocolValidatorInterface;
use Ucp\Sdk\Symfony\Bridge\HttpPayloadMapper;

/** @internal */
final class ShoppingOperationExecutor
{
    /**
     * @param iterable<CheckoutRequestValidatorInterface> $requestValidators
     * @param iterable<CheckoutResponseAugmenterInterface> $responseAugmenters
     * @param iterable<PaymentMandateVerifierInterface> $mandateVerifiers
     */
    public function __construct(
        private readonly CapabilityRegistryInterface $capabilityRegistry,
        private readonly ProtocolValidatorInterface $protocolValidator,
        private readonly HttpPayloadMapper $payloadMapper,
        private readonly iterable $requestValidators,
        private readonly iterable $responseAugmenters,
        private readonly iterable $mandateVerifiers,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(ShoppingOperationRequest $request): array
    {
        return match ($request->operation) {
            'catalog.search' => $this->catalogSearch($request),
            'catalog.lookup' => $this->catalogLookup($request),
            'catalog.product' => $this->productGet($request),
            'cart.create' => $this->cartCreate($request),
            'cart.get' => $this->cartGet($request),
            'cart.update' => $this->cartUpdate($request),
            'cart.cancel' => $this->cartCancel($request),
            'discount.apply' => $this->discountApply($request),
            'checkout.create' => $this->checkoutCreate($request),
            'checkout.get' => $this->checkoutGet($request),
            'checkout.update' => $this->checkoutUpdate($request),
            'checkout.complete' => $this->checkoutComplete($request),
            'checkout.cancel' => $this->checkoutCancel($request),
            'order.get' => $this->orderGet($request),
            default => throw new UnsupportedCapabilityException(sprintf('Shopping operation "%s" is not supported.', $request->operation)),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogSearch(ShoppingOperationRequest $request): array
    {
        $this->protocolValidator->validateRequest('catalog.search', $request->payload, $request->context);
        $result = $this->catalog()->search($this->payloadMapper->toCatalogSearchRequest($request->payload), $request->context)->toArray();
        $this->protocolValidator->validateResponse('catalog.search', $result, $request->context);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogLookup(ShoppingOperationRequest $request): array
    {
        $this->protocolValidator->validateRequest('catalog.lookup', $request->payload, $request->context);
        $result = [
            'items' => array_map(static fn (Product $product): array => $product->toArray(), $this->catalog()->lookup($this->payloadMapper->toCatalogLookupRequest($request->payload), $request->context)),
        ];
        $this->protocolValidator->validateResponse('catalog.lookup', $result, $request->context);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function productGet(ShoppingOperationRequest $request): array
    {
        $this->protocolValidator->validateRequest('catalog.product', $request->payload, $request->context);
        $id = $this->requiredId($request);
        $result = $this->catalog()->getProduct($id, $request->context)->toArray();
        $this->protocolValidator->validateResponse('catalog.product', $result, $request->context);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function cartCreate(ShoppingOperationRequest $request): array
    {
        $this->protocolValidator->validateRequest('cart.create', $request->payload, $request->context);
        $result = $this->cart()->createCart($this->payloadMapper->toCartCreateRequest($request->payload), $request->context)->toArray();
        $this->protocolValidator->validateResponse('cart.create', $result, $request->context);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function cartGet(ShoppingOperationRequest $request): array
    {
        $this->protocolValidator->validateRequest('cart.get', $request->payload, $request->context);
        $id = $this->requiredId($request);
        $result = $this->cart()->getCart($id, $request->context)->toArray();
        $this->protocolValidator->validateResponse('cart.get', $result, $request->context);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function cartUpdate(ShoppingOperationRequest $request): array
    {
        $this->protocolValidator->validateRequest('cart.update', $request->payload, $request->context);
        $result = $this->cart()->updateCart($this->payloadMapper->toCartUpdateRequest($this->requiredId($request), $request->payload), $request->context)->toArray();
        $this->protocolValidator->validateResponse('cart.update', $result, $request->context);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function cartCancel(ShoppingOperationRequest $request): array
    {
        $this->protocolValidator->validateRequest('cart.cancel', $request->payload, $request->context);
        $id = $this->requiredId($request);
        $result = $this->cart()->cancelCart($id, $request->context)->toArray();
        $this->protocolValidator->validateResponse('cart.cancel', $result, $request->context);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function discountApply(ShoppingOperationRequest $request): array
    {
        $this->protocolValidator->validateRequest('discount.apply', $request->payload, $request->context);
        $cartId = (string) ($request->payload['cart_id'] ?? $request->id ?? '');
        $code = (string) ($request->payload['code'] ?? '');
        if ($cartId === '' || $code === '') {
            throw new BadRequestHttpException('discount.apply requires cart_id and code parameters.');
        }
        $result = $this->discount()->applyCartDiscount($cartId, new DiscountCode($code), $request->context)->toArray();
        $this->protocolValidator->validateResponse('discount.apply', $result, $request->context);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function checkoutCreate(ShoppingOperationRequest $request): array
    {
        $this->protocolValidator->validateRequest('checkout.create', $request->payload, $request->context);
        $checkoutRequest = $this->payloadMapper->toCheckoutCreateRequest($request->payload);

        foreach ($this->requestValidators as $validator) {
            $validator->validate($checkoutRequest, $request->context);
        }

        $event = new CheckoutRequestReceivedEvent($checkoutRequest, $request->context);
        $this->eventDispatcher->dispatch($event);

        $result = $this->finalizeCheckout($this->checkout()->createCheckout($event->getRequest(), $request->context), $request)->toArray();
        $this->protocolValidator->validateResponse('checkout.create', $result, $request->context);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function checkoutGet(ShoppingOperationRequest $request): array
    {
        $this->protocolValidator->validateRequest('checkout.get', $request->payload, $request->context);
        $id = $this->requiredId($request);
        $result = $this->finalizeCheckout($this->checkout()->getCheckout($id, $request->context), $request)->toArray();
        $this->protocolValidator->validateResponse('checkout.get', $result, $request->context);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function checkoutUpdate(ShoppingOperationRequest $request): array
    {
        $this->protocolValidator->validateRequest('checkout.update', $request->payload, $request->context);
        $checkoutRequest = $this->payloadMapper->toCheckoutUpdateRequest($this->requiredId($request), $request->payload);

        if ($checkoutRequest->payment !== null) {
            foreach ($this->mandateVerifiers as $verifier) {
                $verifier->verify($checkoutRequest->payment, $request->context);
            }

            $this->eventDispatcher->dispatch(new PaymentMandateVerificationEvent($checkoutRequest->payment, $request->context));
        }

        $result = $this->finalizeCheckout($this->checkout()->updateCheckout($checkoutRequest, $request->context), $request)->toArray();
        $this->protocolValidator->validateResponse('checkout.update', $result, $request->context);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function checkoutComplete(ShoppingOperationRequest $request): array
    {
        $this->protocolValidator->validateRequest('checkout.complete', $request->payload, $request->context);
        $id = $this->requiredId($request);
        $result = $this->finalizeCheckout($this->checkout()->completeCheckout($id, $request->context), $request)->toArray();
        $this->protocolValidator->validateResponse('checkout.complete', $result, $request->context);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function checkoutCancel(ShoppingOperationRequest $request): array
    {
        $this->protocolValidator->validateRequest('checkout.cancel', $request->payload, $request->context);
        $id = $this->requiredId($request);
        $result = $this->finalizeCheckout($this->checkout()->cancelCheckout($id, $request->context), $request)->toArray();
        $this->protocolValidator->validateResponse('checkout.cancel', $result, $request->context);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function orderGet(ShoppingOperationRequest $request): array
    {
        $this->protocolValidator->validateRequest('order.get', $request->payload, $request->context);
        $id = $this->requiredId($request);
        $result = $this->order()->getOrder($id, $request->context)->toArray();
        $this->protocolValidator->validateResponse('order.get', $result, $request->context);

        return $result;
    }

    private function catalog(): CatalogCapabilityInterface
    {
        $capability = $this->capabilityRegistry->firstImplementing(CatalogCapabilityInterface::class);
        if (! $capability instanceof CatalogCapabilityInterface) {
            throw new UnsupportedCapabilityException('Catalog capability is not registered.');
        }

        return $capability;
    }

    private function cart(): CartCapabilityInterface
    {
        $capability = $this->capabilityRegistry->firstImplementing(CartCapabilityInterface::class);
        if (! $capability instanceof CartCapabilityInterface) {
            throw new UnsupportedCapabilityException('Cart capability is not registered.');
        }

        return $capability;
    }

    private function checkout(): CheckoutCapabilityInterface
    {
        $capability = $this->capabilityRegistry->firstImplementing(CheckoutCapabilityInterface::class);
        if (! $capability instanceof CheckoutCapabilityInterface) {
            throw new UnsupportedCapabilityException('Checkout capability is not registered.');
        }

        return $capability;
    }

    private function discount(): DiscountCapabilityInterface
    {
        $capability = $this->capabilityRegistry->firstImplementing(DiscountCapabilityInterface::class);
        if (! $capability instanceof DiscountCapabilityInterface) {
            throw new UnsupportedCapabilityException('Discount capability is not registered.');
        }

        return $capability;
    }

    private function order(): OrderCapabilityInterface
    {
        $capability = $this->capabilityRegistry->firstImplementing(OrderCapabilityInterface::class);
        if (! $capability instanceof OrderCapabilityInterface) {
            throw new UnsupportedCapabilityException('Order capability is not registered.');
        }

        return $capability;
    }

    private function finalizeCheckout(Checkout $checkout, ShoppingOperationRequest $request): Checkout
    {
        foreach ($this->responseAugmenters as $augmenter) {
            $checkout = $augmenter->augment($checkout, $request->context);
        }

        $event = new CheckoutResponsePreparedEvent($checkout, $request->context);
        $this->eventDispatcher->dispatch($event);

        return $event->getCheckout();
    }

    private function requiredId(ShoppingOperationRequest $request): string
    {
        $id = $request->id ?? ($request->payload['id'] ?? null);
        if (! is_string($id) || $id === '') {
            throw new BadRequestHttpException(sprintf('%s requires a non-empty string id.', $request->operation));
        }

        return $id;
    }
}
