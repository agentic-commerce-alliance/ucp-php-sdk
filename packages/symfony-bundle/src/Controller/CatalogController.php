<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
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
        private readonly bool $legacyProductGetRoute = true,
    ) {
    }

    #[Route(path: '/ucp/v1/catalog/search', methods: ['POST'])]
    public function search(Request $request): Response
    {
        return $this->responseFactory->operation($this->operationExecutor->execute(new ShoppingOperationRequest(
            'catalog.search',
            $this->payloadMapper->decode($request),
            $request->attributes->get('ucp_request_context'),
        )), context: $request->attributes->get('ucp_request_context'), operation: 'catalog.search');
    }

    #[Route(path: '/ucp/v1/catalog/lookup', methods: ['POST'])]
    public function lookup(Request $request): Response
    {
        return $this->responseFactory->operation($this->operationExecutor->execute(new ShoppingOperationRequest(
            'catalog.lookup',
            $this->payloadMapper->decode($request),
            $request->attributes->get('ucp_request_context'),
        )), context: $request->attributes->get('ucp_request_context'), operation: 'catalog.lookup');
    }

    #[Route(path: '/ucp/v1/catalog/product', methods: ['POST'])]
    public function product(Request $request): Response
    {
        return $this->responseFactory->operation($this->operationExecutor->execute(new ShoppingOperationRequest(
            'catalog.product',
            $this->payloadMapper->decode($request),
            $request->attributes->get('ucp_request_context'),
        )), context: $request->attributes->get('ucp_request_context'), operation: 'catalog.product');
    }

    /**
     * The shape this SDK shipped before 0.0.6, retained for one minor.
     *
     * It was never conformant -- `services/shopping/rest.openapi.json` has defined
     * `POST /catalog/product` at every published protocol version -- so no conformant peer can
     * depend on it, but adopters can, and this one has shipped to merchants. It also cannot
     * carry the request body the operation is defined to take: `catalog.product.request` has
     * seven properties and this route can only ever supply `id`.
     */
    #[Route(path: '/ucp/v1/catalog/product/{id}', methods: ['GET'])]
    public function legacyProductById(string $id, Request $request): Response
    {
        if (! $this->legacyProductGetRoute) {
            throw new NotFoundHttpException('The GET catalog product route is disabled; use POST /ucp/v1/catalog/product.');
        }

        return $this->responseFactory->operation($this->operationExecutor->execute(new ShoppingOperationRequest(
            'catalog.product',
            [],
            $request->attributes->get('ucp_request_context'),
            $id,
        )), context: $request->attributes->get('ucp_request_context'), operation: 'catalog.product');
    }
}
