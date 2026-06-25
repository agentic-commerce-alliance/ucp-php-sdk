<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Ucp\Sdk\Symfony\Bridge\UcpResponseFactory;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationExecutor;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationRequest;

/** @internal */
final class OrderController
{
    public function __construct(
        private readonly UcpResponseFactory $responseFactory,
        private readonly ShoppingOperationExecutor $operationExecutor,
    ) {
    }

    #[Route(path: '/ucp/v1/orders', methods: ['GET'])]
    public function missingId(Request $request): Response
    {
        return $this->responseFactory->error('Order id is required.', 400, context: $request->attributes->get('ucp_request_context'), operation: 'order.get');
    }

    #[Route(path: '/ucp/v1/orders/{id}', methods: ['GET'])]
    public function get(string $id, Request $request): Response
    {
        return $this->responseFactory->operation($this->operationExecutor->execute(new ShoppingOperationRequest(
            'order.get',
            [],
            $request->attributes->get('ucp_request_context'),
            $id,
        )), context: $request->attributes->get('ucp_request_context'), operation: 'order.get');
    }
}
