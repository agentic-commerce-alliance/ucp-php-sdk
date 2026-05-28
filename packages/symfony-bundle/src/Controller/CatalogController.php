<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Ucp\Sdk\Contract\CatalogCapabilityInterface;
use Ucp\Sdk\Exception\UnsupportedCapabilityException;
use Ucp\Sdk\Service\CapabilityRegistryInterface;
use Ucp\Sdk\Service\ProtocolValidatorInterface;
use Ucp\Sdk\Symfony\Bridge\HttpPayloadMapper;
use Ucp\Sdk\Symfony\Bridge\UcpResponseFactory;

final readonly class CatalogController
{
    public function __construct(
        private CapabilityRegistryInterface $capabilityRegistry,
        private ProtocolValidatorInterface $protocolValidator,
        private HttpPayloadMapper $payloadMapper,
        private UcpResponseFactory $responseFactory,
    ) {
    }

    #[Route(path: '/ucp/v1/catalog/search', methods: ['POST'])]
    public function search(Request $request): Response
    {
        $payload = $this->payloadMapper->decode($request);
        $context = $request->attributes->get('ucp_request_context');
        $this->protocolValidator->validateRequest('catalog.search', $payload, $context);

        $capability = $this->capabilityRegistry->firstImplementing(CatalogCapabilityInterface::class);
        if (! $capability instanceof CatalogCapabilityInterface) {
            throw new UnsupportedCapabilityException('Catalog capability is not registered.');
        }

        $result = $capability->search($this->payloadMapper->toCatalogSearchRequest($payload), $context);
        $responsePayload = $result->toArray();
        $this->protocolValidator->validateResponse('catalog.search', $responsePayload, $context);

        return $this->responseFactory->success($responsePayload);
    }

    #[Route(path: '/ucp/v1/catalog/lookup', methods: ['POST'])]
    public function lookup(Request $request): Response
    {
        $payload = $this->payloadMapper->decode($request);
        $context = $request->attributes->get('ucp_request_context');
        $this->protocolValidator->validateRequest('catalog.lookup', $payload, $context);
        $capability = $this->capabilityRegistry->firstImplementing(CatalogCapabilityInterface::class);
        if (! $capability instanceof CatalogCapabilityInterface) {
            throw new UnsupportedCapabilityException('Catalog capability is not registered.');
        }

        $products = $capability->lookup($this->payloadMapper->toCatalogLookupRequest($payload), $context);
        $responsePayload = [
            'items' => array_map(static fn ($product): array => $product->toArray(), $products),
        ];
        $this->protocolValidator->validateResponse('catalog.lookup', $responsePayload, $context);

        return $this->responseFactory->success($responsePayload);
    }

    #[Route(path: '/ucp/v1/catalog/product/{id}', methods: ['GET'])]
    public function product(string $id, Request $request): Response
    {
        $capability = $this->capabilityRegistry->firstImplementing(CatalogCapabilityInterface::class);
        if (! $capability instanceof CatalogCapabilityInterface) {
            throw new UnsupportedCapabilityException('Catalog capability is not registered.');
        }

        $product = $capability->getProduct($id, $request->attributes->get('ucp_request_context'));

        return $this->responseFactory->success($product->toArray());
    }
}
