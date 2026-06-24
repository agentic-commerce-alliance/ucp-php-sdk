<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Ucp\Sdk\Symfony\Bridge\HttpPayloadMapper;
use Ucp\Sdk\Symfony\Bridge\UcpResponseFactory;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationExecutor;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationRequest;

/** @internal */
final class CheckoutController
{
    public function __construct(
        private readonly HttpPayloadMapper $payloadMapper,
        private readonly UcpResponseFactory $responseFactory,
        private readonly ShoppingOperationExecutor $operationExecutor,
    ) {
    }

    #[Route(path: '/ucp/v1/checkout-sessions', methods: ['POST'])]
    public function create(Request $request): Response
    {
        return $this->responseFactory->operation($this->operationExecutor->execute(new ShoppingOperationRequest(
            'checkout.create',
            $this->payloadMapper->decode($request),
            $request->attributes->get('ucp_request_context'),
        )), 201, context: $request->attributes->get('ucp_request_context'), operation: 'checkout.create');
    }

    #[Route(path: '/ucp/v1/checkout-sessions/{id}', methods: ['GET'])]
    public function get(string $id, Request $request): Response
    {
        return $this->responseFactory->operation($this->operationExecutor->execute(new ShoppingOperationRequest(
            'checkout.get',
            [],
            $request->attributes->get('ucp_request_context'),
            $id,
        )), context: $request->attributes->get('ucp_request_context'), operation: 'checkout.get');
    }

    #[Route(path: '/ucp/v1/checkout-sessions/{id}', methods: ['PUT', 'PATCH'])]
    public function update(string $id, Request $request): Response
    {
        return $this->responseFactory->operation($this->operationExecutor->execute(new ShoppingOperationRequest(
            'checkout.update',
            $this->payloadMapper->decode($request),
            $request->attributes->get('ucp_request_context'),
            $id,
        )), context: $request->attributes->get('ucp_request_context'), operation: 'checkout.update');
    }

    #[Route(path: '/ucp/v1/checkout-sessions/{id}/complete', methods: ['POST'])]
    public function complete(string $id, Request $request): Response
    {
        return $this->responseFactory->operation($this->operationExecutor->execute(new ShoppingOperationRequest(
            'checkout.complete',
            $this->payloadMapper->decode($request),
            $request->attributes->get('ucp_request_context'),
            $id,
        )), context: $request->attributes->get('ucp_request_context'), operation: 'checkout.complete');
    }

    #[Route(path: '/ucp/v1/checkout-sessions/{id}/cancel', methods: ['POST'])]
    public function cancel(string $id, Request $request): Response
    {
        return $this->responseFactory->operation($this->operationExecutor->execute(new ShoppingOperationRequest(
            'checkout.cancel',
            [],
            $request->attributes->get('ucp_request_context'),
            $id,
        )), context: $request->attributes->get('ucp_request_context'), operation: 'checkout.cancel');
    }
}
