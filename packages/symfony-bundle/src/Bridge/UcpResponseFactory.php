<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Bridge;

use Symfony\Component\HttpFoundation\JsonResponse;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\Profile\PaymentHandlerDescriptor;
use Ucp\Sdk\Model\Protocol\UcpOperationResponse;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Symfony\UcpSdkConfiguration;

/** @internal */
final class UcpResponseFactory
{
    public function __construct(
        private readonly UcpSdkConfiguration $configuration,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     */
    public function success(array $payload, int $status = 200, array $headers = [], ?RequestContext $context = null, ?string $operation = null): JsonResponse
    {
        if (array_key_exists('ucp', $payload)) {
            throw new \LogicException('Top-level "ucp" is reserved for the protocol envelope.');
        }

        $payload['ucp'] = $this->ucpEnvelope('success', $context, $operation);

        return new JsonResponse($payload, $status, $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    public function operation(UcpOperationResponse $response, int $status = 200, array $headers = [], ?RequestContext $context = null, ?string $operation = null): JsonResponse
    {
        return new JsonResponse($response, $status, $headers);
    }

    /**
     * @param list<array<string, string>> $messages
     */
    public function error(string $message, int $status = 400, array $messages = [], ?RequestContext $context = null, ?string $operation = null): JsonResponse
    {
        return new JsonResponse([
            'ucp' => $this->ucpEnvelope('error', $context, $operation),
            'messages' => $messages !== [] ? $messages : [[
                'type' => 'error',
                'content' => $message,
            ]],
        ], $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function ucpEnvelope(string $status, ?RequestContext $context, ?string $operation): array
    {
        $version = $this->configuration->version;
        if ($context?->runtimeConfiguration !== null) {
            $version = $context->runtimeConfiguration->version;
        }

        $envelope = [
            'version' => $version,
            'status' => $status,
        ];

        if ($context?->negotiation === null) {
            return $envelope;
        }

        $capabilities = $this->capabilitiesForOperation($context, $operation);
        if ($capabilities !== []) {
            $envelope['capabilities'] = $capabilities;
        }

        if ($operation !== null && str_starts_with($operation, 'checkout.') && $context->negotiation->paymentHandlers !== []) {
            $envelope['payment_handlers'] = $this->paymentHandlers($context);
        }

        return $envelope;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function capabilitiesForOperation(RequestContext $context, ?string $operation): array
    {
        $names = $operation !== null
            ? $context->negotiation?->capabilitiesForOperation($operation) ?? []
            : $context->negotiation?->capabilityNames() ?? [];
        $capabilities = [];

        foreach ($names as $name) {
            $entries = $context->negotiation?->capabilities[$name] ?? [];
            if ($entries === []) {
                continue;
            }

            $capabilities[$name] = array_map(
                static fn (CapabilityDescriptor $descriptor): array => $descriptor->toProfileEntry(),
                $entries,
            );
        }

        return $capabilities;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function paymentHandlers(RequestContext $context): array
    {
        if ($context->negotiation === null) {
            return [];
        }

        $handlers = [];

        foreach ($context->negotiation->paymentHandlers as $name => $entries) {
            $handlers[$name] = array_map(
                static fn (PaymentHandlerDescriptor $descriptor): array => $descriptor->toArray(),
                $entries,
            );
        }

        return $handlers;
    }
}
