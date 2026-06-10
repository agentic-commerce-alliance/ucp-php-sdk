<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Ucp\Sdk\Contract\TokenizationCapabilityInterface;
use Ucp\Sdk\Exception\UnsupportedCapabilityException;
use Ucp\Sdk\Service\CapabilityRegistryInterface;
use Ucp\Sdk\Service\ProtocolValidatorInterface;
use Ucp\Sdk\Symfony\Bridge\HttpPayloadMapper;
use Ucp\Sdk\Symfony\Bridge\UcpResponseFactory;

final class TokenizationController
{
    public function __construct(
        private readonly CapabilityRegistryInterface $capabilityRegistry,
        private readonly ProtocolValidatorInterface $protocolValidator,
        private readonly HttpPayloadMapper $payloadMapper,
        private readonly UcpResponseFactory $responseFactory,
    ) {
    }

    #[Route(path: '/ucp/v1/tokenize', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $payload = $this->payloadMapper->decode($request);
        $context = $request->attributes->get('ucp_request_context');
        $this->protocolValidator->validateRequest('tokenization', $payload, $context);
        $capability = $this->capabilityRegistry->firstImplementing(TokenizationCapabilityInterface::class);

        if (! $capability instanceof TokenizationCapabilityInterface) {
            throw new UnsupportedCapabilityException('Tokenization capability is not registered.');
        }

        $result = $capability->tokenize($this->payloadMapper->toPaymentInstrument($payload), $context);
        $this->protocolValidator->validateResponse('tokenization', $result, $context);

        return $this->responseFactory->success($result);
    }
}
