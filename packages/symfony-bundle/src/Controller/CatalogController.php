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
final class CatalogController
{
    public function __construct(
        private readonly HttpPayloadMapper $payloadMapper,
        private readonly UcpResponseFactory $responseFactory,
        private readonly ShoppingOperationExecutor $operationExecutor,
    ) {
    }

    #[Route(path: '/ucp/v1/catalog/search', methods: ['POST'])]
    public function search(Request $request): Response
    {
        return $this->responseFactory->success($this->operationExecutor->execute(new ShoppingOperationRequest(
            'catalog.search',
            $this->payloadMapper->decode($request),
            $request->attributes->get('ucp_request_context'),
        )), context: $request->attributes->get('ucp_request_context'), operation: 'catalog.search');
    }

    #[Route(path: '/ucp/v1/catalog/lookup', methods: ['POST'])]
    public function lookup(Request $request): Response
    {
        return $this->responseFactory->success($this->operationExecutor->execute(new ShoppingOperationRequest(
            'catalog.lookup',
            $this->payloadMapper->decode($request),
            $request->attributes->get('ucp_request_context'),
        )), context: $request->attributes->get('ucp_request_context'), operation: 'catalog.lookup');
    }

    #[Route(path: '/ucp/v1/catalog/product/{id}', methods: ['GET'])]
    public function product(string $id, Request $request): Response
    {
        return $this->responseFactory->success($this->operationExecutor->execute(new ShoppingOperationRequest(
            'catalog.product',
            [],
            $request->attributes->get('ucp_request_context'),
            $id,
        )), context: $request->attributes->get('ucp_request_context'), operation: 'catalog.product');
    }
}
