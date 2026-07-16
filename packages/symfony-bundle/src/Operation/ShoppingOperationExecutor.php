<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Operation;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Ucp\Sdk\Contract\Ap2CheckoutMandateVerifierInterface;
use Ucp\Sdk\Contract\CapabilityInterface;
use Ucp\Sdk\Contract\CartCapabilityInterface;
use Ucp\Sdk\Contract\CatalogCapabilityInterface;
use Ucp\Sdk\Contract\CheckoutCapabilityInterface;
use Ucp\Sdk\Contract\CheckoutRequestValidatorInterface;
use Ucp\Sdk\Contract\CheckoutResponseAugmenterInterface;
use Ucp\Sdk\Contract\DiscountCapabilityInterface;
use Ucp\Sdk\Contract\OrderCapabilityInterface;
use Ucp\Sdk\Contract\PaymentMandateVerifierInterface;
use Ucp\Sdk\Enum\UcpCapability;
use Ucp\Sdk\Enum\UcpProtocolVersion;
use Ucp\Sdk\Enum\UcpResponseStatus;
use Ucp\Sdk\Event\CheckoutRequestReceivedEvent;
use Ucp\Sdk\Event\CheckoutResponsePreparedEvent;
use Ucp\Sdk\Event\PaymentMandateVerificationEvent;
use Ucp\Sdk\Exception\NegotiationException;
use Ucp\Sdk\Exception\UnsupportedCapabilityException;
use Ucp\Sdk\Model\Catalog\CatalogLookupResponse;
use Ucp\Sdk\Model\Catalog\CatalogProductResponse;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Checkout\DiscountCode;
use Ucp\Sdk\Model\Protocol\UcpEnvelope;
use Ucp\Sdk\Model\Protocol\UcpOperationPayload;
use Ucp\Sdk\Model\Protocol\UcpOperationResponse;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Service\CapabilityRegistryInterface;
use Ucp\Sdk\Service\CheckoutMerchantAuthorizationSignerInterface;
use Ucp\Sdk\Service\ProtocolValidatorInterface;
use Ucp\Sdk\Symfony\Bridge\HttpPayloadMapper;

/** @internal */
final class ShoppingOperationExecutor
{
    /**
     * @param iterable<CheckoutRequestValidatorInterface> $requestValidators
     * @param iterable<CheckoutResponseAugmenterInterface> $responseAugmenters
     * @param iterable<PaymentMandateVerifierInterface> $mandateVerifiers
     * @param iterable<Ap2CheckoutMandateVerifierInterface> $ap2CheckoutMandateVerifiers
     */
    public function __construct(
        private readonly CapabilityRegistryInterface $capabilityRegistry,
        private readonly ProtocolValidatorInterface $protocolValidator,
        private readonly HttpPayloadMapper $payloadMapper,
        private readonly iterable $requestValidators,
        private readonly iterable $responseAugmenters,
        private readonly iterable $mandateVerifiers,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly iterable $ap2CheckoutMandateVerifiers = [],
        private readonly ?CheckoutMerchantAuthorizationSignerInterface $checkoutMerchantAuthorizationSigner = null,
    ) {
    }

    public function execute(ShoppingOperationRequest $request): UcpOperationResponse
    {
        $this->assertNegotiated($request);

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

    private function assertNegotiated(ShoppingOperationRequest $request): void
    {
        $context = $request->context;
        if ($context->platformProfile === null) {
            return;
        }

        $configuration = $context->runtimeConfiguration;
        if ($configuration !== null) {
            $supportedVersions = [$configuration->version, ...array_keys($configuration->supportedVersions)];
            if (! in_array($context->platformProfile->version, $supportedVersions, true)) {
                throw NegotiationException::versionUnsupported();
            }
        }

        if ($context->negotiation === null || $context->negotiation->capabilitiesForOperation($request->operation) === []) {
            throw NegotiationException::capabilitiesIncompatible();
        }
    }

    private function catalogSearch(ShoppingOperationRequest $request): UcpOperationResponse
    {
        $this->protocolValidator->validateRequest('catalog.search', $request->payload, $request->context);

        return $this->response(
            'catalog.search',
            $this->catalog($request->context)->search($this->payloadMapper->toCatalogSearchRequest($request->payload), $request->context),
            UcpCapability::CatalogSearch,
            $request->context,
        );
    }

    private function catalogLookup(ShoppingOperationRequest $request): UcpOperationResponse
    {
        $this->protocolValidator->validateRequest('catalog.lookup', $request->payload, $request->context);
        $result = new CatalogLookupResponse(
            $this->catalog($request->context)->lookup($this->payloadMapper->toCatalogLookupRequest($request->payload), $request->context),
        );

        return $this->response('catalog.lookup', $result, UcpCapability::CatalogLookup, $request->context);
    }

    private function productGet(ShoppingOperationRequest $request): UcpOperationResponse
    {
        $id = $this->requiredId($request);
        $payload = ['id' => $id, ...$request->payload];
        $this->protocolValidator->validateRequest('catalog.product', $payload, $request->context);
        $productRequest = $this->payloadMapper->toCatalogProductRequest($payload);

        return $this->response(
            'catalog.product',
            new CatalogProductResponse($this->catalog($request->context)->getProduct($productRequest, $request->context)),
            UcpCapability::CatalogProduct,
            $request->context,
        );
    }

    private function cartCreate(ShoppingOperationRequest $request): UcpOperationResponse
    {
        $this->protocolValidator->validateRequest('cart.create', $request->payload, $request->context);

        return $this->response(
            'cart.create',
            $this->cart($request->context)->createCart($this->payloadMapper->toCartCreateRequest($request->payload), $request->context),
            UcpCapability::Cart,
            $request->context,
        );
    }

    private function cartGet(ShoppingOperationRequest $request): UcpOperationResponse
    {
        $id = $this->requiredId($request);
        $this->protocolValidator->validateRequest('cart.get', ['id' => $id, ...$request->payload], $request->context);

        return $this->response('cart.get', $this->cart($request->context)->getCart($id, $request->context), UcpCapability::Cart, $request->context);
    }

    private function cartUpdate(ShoppingOperationRequest $request): UcpOperationResponse
    {
        $this->protocolValidator->validateRequest('cart.update', $request->payload, $request->context);

        return $this->response(
            'cart.update',
            $this->cart($request->context)->updateCart($this->payloadMapper->toCartUpdateRequest($this->requiredId($request), $request->payload), $request->context),
            UcpCapability::Cart,
            $request->context,
        );
    }

    private function cartCancel(ShoppingOperationRequest $request): UcpOperationResponse
    {
        $id = $this->requiredId($request);
        $this->protocolValidator->validateRequest('cart.cancel', ['id' => $id, ...$request->payload], $request->context);

        return $this->response('cart.cancel', $this->cart($request->context)->cancelCart($id, $request->context), UcpCapability::Cart, $request->context);
    }

    private function discountApply(ShoppingOperationRequest $request): UcpOperationResponse
    {
        $this->protocolValidator->validateRequest('discount.apply', $request->payload, $request->context);
        $cartId = (string) ($request->payload['cart_id'] ?? $request->id ?? '');
        $code = (string) ($request->payload['code'] ?? '');
        if ($cartId === '' || $code === '') {
            throw new BadRequestHttpException('discount.apply requires cart_id and code parameters.');
        }
        return $this->response(
            'discount.apply',
            $this->discount($request->context)->applyCartDiscount($cartId, new DiscountCode($code), $request->context),
            UcpCapability::Cart,
            $request->context,
        );
    }

    private function checkoutCreate(ShoppingOperationRequest $request): UcpOperationResponse
    {
        $this->protocolValidator->validateRequest('checkout.create', $request->payload, $request->context);
        $checkoutRequest = $this->payloadMapper->toCheckoutCreateRequest($request->payload);

        foreach ($this->requestValidators as $validator) {
            $validator->validate($checkoutRequest, $request->context);
        }

        $event = new CheckoutRequestReceivedEvent($checkoutRequest, $request->context);
        $this->eventDispatcher->dispatch($event);

        return $this->response(
            'checkout.create',
            $this->finalizeCheckout($this->checkout($request->context)->createCheckout($event->getRequest(), $request->context), $request),
            UcpCapability::Checkout,
            $request->context,
        );
    }

    private function checkoutGet(ShoppingOperationRequest $request): UcpOperationResponse
    {
        $id = $this->requiredId($request);
        $this->protocolValidator->validateRequest('checkout.get', ['id' => $id, ...$request->payload], $request->context);
        return $this->response('checkout.get', $this->finalizeCheckout($this->checkout($request->context)->getCheckout($id, $request->context), $request), UcpCapability::Checkout, $request->context);
    }

    private function checkoutUpdate(ShoppingOperationRequest $request): UcpOperationResponse
    {
        $this->protocolValidator->validateRequest('checkout.update', $request->payload, $request->context);
        $checkoutRequest = $this->payloadMapper->toCheckoutUpdateRequest($this->requiredId($request), $request->payload);

        if ($checkoutRequest->payment !== null) {
            foreach ($this->mandateVerifiers as $verifier) {
                $verifier->verify($checkoutRequest->payment, $request->context);
            }

            $this->eventDispatcher->dispatch(new PaymentMandateVerificationEvent($checkoutRequest->payment, $request->context));
        }

        return $this->response(
            'checkout.update',
            $this->finalizeCheckout($this->checkout($request->context)->updateCheckout($checkoutRequest, $request->context), $request),
            UcpCapability::Checkout,
            $request->context,
        );
    }

    private function checkoutComplete(ShoppingOperationRequest $request): UcpOperationResponse
    {
        $this->protocolValidator->validateRequest('checkout.complete', $request->payload, $request->context);
        $completeRequest = $this->payloadMapper->toCheckoutCompleteRequest($this->requiredId($request), $request->payload);
        $checkoutCapability = $this->checkout($request->context);

        if ($completeRequest->payment !== null) {
            foreach ($completeRequest->payment->instruments as $instrument) {
                foreach ($this->mandateVerifiers as $verifier) {
                    $verifier->verify($instrument, $request->context);
                }

                $this->eventDispatcher->dispatch(new PaymentMandateVerificationEvent($instrument, $request->context));
            }
        }

        $currentCheckout = null;
        foreach ($this->ap2CheckoutMandateVerifiers as $verifier) {
            $currentCheckout ??= $checkoutCapability->getCheckout($completeRequest->id, $request->context);
            $verifier->verify($completeRequest, $currentCheckout, $request->context);
        }

        $checkout = $this->finalizeCheckout($checkoutCapability->completeCheckout($completeRequest, $request->context), $request);

        if ($this->checkoutMerchantAuthorizationSigner !== null && $completeRequest->ap2 !== null) {
            $ap2 = is_array($checkout->extra['ap2'] ?? null) ? $checkout->extra['ap2'] : [];
            $ap2['merchant_authorization'] = $this->checkoutMerchantAuthorizationSigner->sign($checkout->toArray(), $request->context);
            $checkout = $checkout->withExtra(['ap2' => $ap2]);
        }

        return $this->response('checkout.complete', $checkout, UcpCapability::Checkout, $request->context);
    }

    private function checkoutCancel(ShoppingOperationRequest $request): UcpOperationResponse
    {
        $id = $this->requiredId($request);
        $this->protocolValidator->validateRequest('checkout.cancel', ['id' => $id, ...$request->payload], $request->context);
        return $this->response('checkout.cancel', $this->finalizeCheckout($this->checkout($request->context)->cancelCheckout($id, $request->context), $request), UcpCapability::Checkout, $request->context);
    }

    private function orderGet(ShoppingOperationRequest $request): UcpOperationResponse
    {
        $id = $this->requiredId($request);
        $this->protocolValidator->validateRequest('order.get', ['id' => $id, ...$request->payload], $request->context);
        return $this->response('order.get', $this->order($request->context)->getOrder($id, $request->context), UcpCapability::Order, $request->context);
    }

    private function catalog(RequestContext $context): CatalogCapabilityInterface
    {
        $capability = $this->capabilityRegistry->firstImplementing(CatalogCapabilityInterface::class);
        if (! $capability instanceof CatalogCapabilityInterface) {
            throw new UnsupportedCapabilityException('Catalog capability is not registered.');
        }

        $this->assertCapabilityEnabled($capability, $context, 'Catalog');

        return $capability;
    }

    private function cart(RequestContext $context): CartCapabilityInterface
    {
        $capability = $this->capabilityRegistry->firstImplementing(CartCapabilityInterface::class);
        if (! $capability instanceof CartCapabilityInterface) {
            throw new UnsupportedCapabilityException('Cart capability is not registered.');
        }

        $this->assertCapabilityEnabled($capability, $context, 'Cart');

        return $capability;
    }

    private function checkout(RequestContext $context): CheckoutCapabilityInterface
    {
        $capability = $this->capabilityRegistry->firstImplementing(CheckoutCapabilityInterface::class);
        if (! $capability instanceof CheckoutCapabilityInterface) {
            throw new UnsupportedCapabilityException('Checkout capability is not registered.');
        }

        $this->assertCapabilityEnabled($capability, $context, 'Checkout');

        return $capability;
    }

    private function discount(RequestContext $context): DiscountCapabilityInterface
    {
        $capability = $this->capabilityRegistry->firstImplementing(DiscountCapabilityInterface::class);
        if (! $capability instanceof DiscountCapabilityInterface) {
            throw new UnsupportedCapabilityException('Discount capability is not registered.');
        }

        $this->assertCapabilityEnabled($capability, $context, 'Discount');

        return $capability;
    }

    private function order(RequestContext $context): OrderCapabilityInterface
    {
        $capability = $this->capabilityRegistry->firstImplementing(OrderCapabilityInterface::class);
        if (! $capability instanceof OrderCapabilityInterface) {
            throw new UnsupportedCapabilityException('Order capability is not registered.');
        }

        $this->assertCapabilityEnabled($capability, $context, 'Order');

        return $capability;
    }

    private function assertCapabilityEnabled(CapabilityInterface $capability, RequestContext $context, string $label): void
    {
        if ($context->runtimeConfiguration === null || $context->runtimeConfiguration->isCapabilityEnabled($capability->describe()->name)) {
            return;
        }

        throw new UnsupportedCapabilityException(sprintf('%s capability is disabled by runtime configuration.', $label));
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

    /**
     */
    private function response(string $operation, UcpOperationPayload $payload, UcpCapability $capability, RequestContext $context): UcpOperationResponse
    {
        $response = new UcpOperationResponse(
            $payload,
            UcpEnvelope::response(UcpProtocolVersion::V20260408->value, UcpResponseStatus::Success, $capability),
        );

        $this->protocolValidator->validateResponse($operation, $response->toArray(), $context);

        return $response;
    }
}
