<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Ucp\Sdk\Contract\CartCapabilityInterface;
use Ucp\Sdk\Exception\UnsupportedCapabilityException;
use Ucp\Sdk\Service\CapabilityRegistryInterface;
use Ucp\Sdk\Service\ProtocolValidatorInterface;
use Ucp\Sdk\Symfony\Bridge\HttpPayloadMapper;
use Ucp\Sdk\Symfony\Bridge\UcpResponseFactory;

final readonly class CartController
{
    public function __construct(
        private CapabilityRegistryInterface $capabilityRegistry,
        private ProtocolValidatorInterface $protocolValidator,
        private HttpPayloadMapper $payloadMapper,
        private UcpResponseFactory $responseFactory,
    ) {
    }

    #[Route(path: '/ucp/v1/carts', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $payload = $this->payloadMapper->decode($request);
        $context = $request->attributes->get('ucp_request_context');
        $this->protocolValidator->validateRequest('cart.create', $payload, $context);
        $capability = $this->requireCapability();
        $result = $capability->createCart($this->payloadMapper->toCartCreateRequest($payload), $context);
        $responsePayload = $result->toArray();
        $this->protocolValidator->validateResponse('cart.create', $responsePayload, $context);

        return $this->responseFactory->success($responsePayload, 201);
    }

    #[Route(path: '/ucp/v1/carts/{id}', methods: ['GET'])]
    public function get(string $id, Request $request): Response
    {
        return $this->responseFactory->success($this->requireCapability()->getCart($id, $request->attributes->get('ucp_request_context'))->toArray());
    }

    #[Route(path: '/ucp/v1/carts/{id}', methods: ['PUT', 'PATCH'])]
    public function update(string $id, Request $request): Response
    {
        $payload = $this->payloadMapper->decode($request);
        $context = $request->attributes->get('ucp_request_context');
        $this->protocolValidator->validateRequest('cart.update', $payload, $context);
        $result = $this->requireCapability()->updateCart($this->payloadMapper->toCartUpdateRequest($id, $payload), $context);
        $responsePayload = $result->toArray();
        $this->protocolValidator->validateResponse('cart.update', $responsePayload, $context);

        return $this->responseFactory->success($responsePayload);
    }

    #[Route(path: '/ucp/v1/carts/{id}/cancel', methods: ['POST'])]
    public function cancel(string $id, Request $request): Response
    {
        return $this->responseFactory->success($this->requireCapability()->cancelCart($id, $request->attributes->get('ucp_request_context'))->toArray());
    }

    private function requireCapability(): CartCapabilityInterface
    {
        $capability = $this->capabilityRegistry->firstImplementing(CartCapabilityInterface::class);
        if (! $capability instanceof CartCapabilityInterface) {
            throw new UnsupportedCapabilityException('Cart capability is not registered.');
        }

        return $capability;
    }
}
