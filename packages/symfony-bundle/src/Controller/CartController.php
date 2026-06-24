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
final class CartController
{
    public function __construct(
        private readonly HttpPayloadMapper $payloadMapper,
        private readonly UcpResponseFactory $responseFactory,
        private readonly ShoppingOperationExecutor $operationExecutor,
    ) {
    }

    #[Route(path: '/ucp/v1/carts', methods: ['POST'])]
    public function create(Request $request): Response
    {
        return $this->responseFactory->success($this->operationExecutor->execute(new ShoppingOperationRequest(
            'cart.create',
            $this->payloadMapper->decode($request),
            $request->attributes->get('ucp_request_context'),
        )), 201, context: $request->attributes->get('ucp_request_context'), operation: 'cart.create');
    }

    #[Route(path: '/ucp/v1/carts/{id}', methods: ['GET'])]
    public function get(string $id, Request $request): Response
    {
        return $this->responseFactory->success($this->operationExecutor->execute(new ShoppingOperationRequest(
            'cart.get',
            [],
            $request->attributes->get('ucp_request_context'),
            $id,
        )), context: $request->attributes->get('ucp_request_context'), operation: 'cart.get');
    }

    #[Route(path: '/ucp/v1/carts/{id}', methods: ['PUT', 'PATCH'])]
    public function update(string $id, Request $request): Response
    {
        return $this->responseFactory->success($this->operationExecutor->execute(new ShoppingOperationRequest(
            'cart.update',
            $this->payloadMapper->decode($request),
            $request->attributes->get('ucp_request_context'),
            $id,
        )), context: $request->attributes->get('ucp_request_context'), operation: 'cart.update');
    }

    #[Route(path: '/ucp/v1/carts/{id}/cancel', methods: ['POST'])]
    public function cancel(string $id, Request $request): Response
    {
        return $this->responseFactory->success($this->operationExecutor->execute(new ShoppingOperationRequest(
            'cart.cancel',
            [],
            $request->attributes->get('ucp_request_context'),
            $id,
        )), context: $request->attributes->get('ucp_request_context'), operation: 'cart.cancel');
    }
}
