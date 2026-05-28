<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Ucp\Sdk\Contract\CheckoutCapabilityInterface;
use Ucp\Sdk\Contract\CheckoutRequestValidatorInterface;
use Ucp\Sdk\Contract\CheckoutResponseAugmenterInterface;
use Ucp\Sdk\Contract\PaymentMandateVerifierInterface;
use Ucp\Sdk\Event\CheckoutRequestReceivedEvent;
use Ucp\Sdk\Event\CheckoutResponsePreparedEvent;
use Ucp\Sdk\Event\PaymentMandateVerificationEvent;
use Ucp\Sdk\Exception\UnsupportedCapabilityException;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Service\CapabilityRegistryInterface;
use Ucp\Sdk\Service\ProtocolValidatorInterface;
use Ucp\Sdk\Symfony\Bridge\HttpPayloadMapper;
use Ucp\Sdk\Symfony\Bridge\UcpResponseFactory;

final readonly class CheckoutController
{
    /**
     * @param iterable<CheckoutRequestValidatorInterface> $requestValidators
     * @param iterable<CheckoutResponseAugmenterInterface> $responseAugmenters
     * @param iterable<PaymentMandateVerifierInterface> $mandateVerifiers
     */
    public function __construct(
        private CapabilityRegistryInterface $capabilityRegistry,
        private ProtocolValidatorInterface $protocolValidator,
        private HttpPayloadMapper $payloadMapper,
        private UcpResponseFactory $responseFactory,
        private iterable $requestValidators,
        private iterable $responseAugmenters,
        private iterable $mandateVerifiers,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    #[Route(path: '/ucp/v1/checkout-sessions', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $payload = $this->payloadMapper->decode($request);
        $context = $request->attributes->get('ucp_request_context');
        $this->protocolValidator->validateRequest('checkout.create', $payload, $context);
        $checkoutRequest = $this->payloadMapper->toCheckoutCreateRequest($payload);

        foreach ($this->requestValidators as $validator) {
            $validator->validate($checkoutRequest, $context);
        }

        $event = new CheckoutRequestReceivedEvent($checkoutRequest, $context);
        $this->eventDispatcher->dispatch($event);

        $checkout = $this->requireCapability()->createCheckout($event->getRequest(), $context);
        $responsePayload = $this->finalizeCheckout($checkout, $context)->toArray();
        $this->protocolValidator->validateResponse('checkout.create', $responsePayload, $context);

        return $this->responseFactory->success($responsePayload, 201);
    }

    #[Route(path: '/ucp/v1/checkout-sessions/{id}', methods: ['GET'])]
    public function get(string $id, Request $request): Response
    {
        $checkout = $this->requireCapability()->getCheckout($id, $request->attributes->get('ucp_request_context'));

        return $this->responseFactory->success($this->finalizeCheckout($checkout, $request->attributes->get('ucp_request_context'))->toArray());
    }

    #[Route(path: '/ucp/v1/checkout-sessions/{id}', methods: ['PUT', 'PATCH'])]
    public function update(string $id, Request $request): Response
    {
        $payload = $this->payloadMapper->decode($request);
        $context = $request->attributes->get('ucp_request_context');
        $this->protocolValidator->validateRequest('checkout.update', $payload, $context);
        $checkoutRequest = $this->payloadMapper->toCheckoutUpdateRequest($id, $payload);

        if ($checkoutRequest->payment !== null) {
            foreach ($this->mandateVerifiers as $verifier) {
                $verifier->verify($checkoutRequest->payment, $context);
            }

            $this->eventDispatcher->dispatch(new PaymentMandateVerificationEvent($checkoutRequest->payment, $context));
        }

        $checkout = $this->requireCapability()->updateCheckout($checkoutRequest, $context);
        $responsePayload = $this->finalizeCheckout($checkout, $context)->toArray();
        $this->protocolValidator->validateResponse('checkout.update', $responsePayload, $context);

        return $this->responseFactory->success($responsePayload);
    }

    #[Route(path: '/ucp/v1/checkout-sessions/{id}/complete', methods: ['POST'])]
    public function complete(string $id, Request $request): Response
    {
        $checkout = $this->requireCapability()->completeCheckout($id, $request->attributes->get('ucp_request_context'));

        return $this->responseFactory->success($this->finalizeCheckout($checkout, $request->attributes->get('ucp_request_context'))->toArray());
    }

    #[Route(path: '/ucp/v1/checkout-sessions/{id}/cancel', methods: ['POST'])]
    public function cancel(string $id, Request $request): Response
    {
        $checkout = $this->requireCapability()->cancelCheckout($id, $request->attributes->get('ucp_request_context'));

        return $this->responseFactory->success($this->finalizeCheckout($checkout, $request->attributes->get('ucp_request_context'))->toArray());
    }

    private function requireCapability(): CheckoutCapabilityInterface
    {
        $capability = $this->capabilityRegistry->firstImplementing(CheckoutCapabilityInterface::class);
        if (! $capability instanceof CheckoutCapabilityInterface) {
            throw new UnsupportedCapabilityException('Checkout capability is not registered.');
        }

        return $capability;
    }

    private function finalizeCheckout(Checkout $checkout, RequestContext $context): Checkout
    {
        foreach ($this->responseAugmenters as $augmenter) {
            $checkout = $augmenter->augment($checkout, $context);
        }

        $event = new CheckoutResponsePreparedEvent($checkout, $context);
        $this->eventDispatcher->dispatch($event);

        return $event->getCheckout();
    }
}
