<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Ucp\Sdk\Contract\OrderCapabilityInterface;
use Ucp\Sdk\Exception\UnsupportedCapabilityException;
use Ucp\Sdk\Service\CapabilityRegistryInterface;
use Ucp\Sdk\Symfony\Bridge\UcpResponseFactory;

final readonly class OrderController
{
    public function __construct(
        private CapabilityRegistryInterface $capabilityRegistry,
        private UcpResponseFactory $responseFactory,
    ) {
    }

    #[Route(path: '/ucp/v1/orders/{id}', methods: ['GET'])]
    public function get(string $id, Request $request): Response
    {
        $capability = $this->capabilityRegistry->firstImplementing(OrderCapabilityInterface::class);
        if (! $capability instanceof OrderCapabilityInterface) {
            throw new UnsupportedCapabilityException('Order capability is not registered.');
        }

        return $this->responseFactory->success(
            $capability->getOrder($id, $request->attributes->get('ucp_request_context'))->toArray(),
        );
    }
}
