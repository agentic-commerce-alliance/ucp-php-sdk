<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Ucp\Sdk\Enum\Transport;
use Ucp\Sdk\Exception\NegotiationException;
use Ucp\Sdk\Exception\UnsupportedCapabilityException;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\Http\HttpRequest;
use Ucp\Sdk\Model\Profile\ProfileBuildInput;
use Ucp\Sdk\Service\ProfileBuilderInterface;
use Ucp\Sdk\Service\RuntimeConfigurationResolverInterface;
use Ucp\Sdk\Symfony\Bridge\HttpPayloadMapper;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationExecutor;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationRequest;
use Ucp\Sdk\Symfony\UcpSdkConfiguration;

/** @internal */
final class A2aController
{
    private const SUPPORTED_METHODS = [
        'catalog.search' => true,
        'catalog.lookup' => true,
        'catalog.product' => true,
        'cart.create' => true,
        'cart.get' => true,
        'cart.update' => true,
        'cart.cancel' => true,
        'discount.apply' => true,
        'checkout.create' => true,
        'checkout.get' => true,
        'checkout.update' => true,
        'checkout.complete' => true,
        'checkout.cancel' => true,
        'order.get' => true,
    ];

    public function __construct(
        private readonly HttpPayloadMapper $payloadMapper,
        private readonly ProfileBuilderInterface $profileBuilder,
        private readonly RuntimeConfigurationResolverInterface $runtimeConfigurationResolver,
        private readonly UcpSdkConfiguration $configuration,
        private readonly ShoppingOperationExecutor $operationExecutor,
    ) {
    }

    #[Route(path: '/.well-known/agent-card.json', methods: ['GET'])]
    public function agentCard(Request $request): Response
    {
        $runtimeConfiguration = $this->runtimeConfigurationResolver->resolve($this->toHttpRequest($request));
        $this->requireTransport($runtimeConfiguration);

        $baseUri = $runtimeConfiguration->baseUri !== '' ? $runtimeConfiguration->baseUri : $this->configuration->resolvedBaseUri($request->getSchemeAndHttpHost());
        $profile = $this->profileBuilder->build(new ProfileBuildInput(
            $runtimeConfiguration->version,
            $baseUri,
            $runtimeConfiguration->transports,
            supportedVersions: $runtimeConfiguration->supportedVersions,
            transportEndpoints: $runtimeConfiguration->transportEndpoints,
            tenantIdentifier: $runtimeConfiguration->tenantIdentifier,
            enabledCapabilities: $runtimeConfiguration->enabledCapabilities,
        ));

        return new JsonResponse([
            'name' => 'Universal Commerce Protocol',
            'description' => 'Commerce agent surface for catalog, cart, checkout, and order capabilities.',
            'url' => $runtimeConfiguration->transportEndpoints[Transport::A2a->value] ?? rtrim($baseUri, '/') . '/ucp/a2a',
            'version' => $runtimeConfiguration->version,
            'protocolVersion' => $runtimeConfiguration->version,
            'capabilities' => [
                'streaming' => false,
                'pushNotifications' => false,
                'stateTransitionHistory' => false,
            ],
            'skills' => array_map(
                static fn (string $name): array => [
                    'id' => $name,
                    'name' => $name,
                    'description' => 'UCP capability exposed through the A2A transport.',
                ],
                array_keys($profile->capabilities),
            ),
            'metadata' => [
                'ucp_profile' => $profile->toArray(),
                'transports' => array_map(static fn (Transport $transport): string => $transport->value, $runtimeConfiguration->transports),
            ],
        ]);
    }

    #[Route(path: '/ucp/a2a', methods: ['POST'])]
    public function invoke(Request $request): Response
    {
        $this->requireTransport($this->runtimeConfigurationResolver->resolve($this->toHttpRequest($request)));

        $id = null;

        try {
            $payload = $this->payloadMapper->decode($request);
            $id = $this->jsonRpcId($payload);
            $method = $this->jsonRpcMethod($payload);
            $params = $this->jsonRpcParams($payload);
            $context = $request->attributes->get('ucp_request_context');

            if (! isset(self::SUPPORTED_METHODS[$method])) {
                throw new UnsupportedCapabilityException(sprintf('A2A method "%s" is not supported.', $method));
            }

            $result = $this->operationExecutor->execute(new ShoppingOperationRequest(
                $method,
                $this->payloadParams($params),
                $context,
                $this->optionalId($params),
            ));

            return new JsonResponse([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => $result,
            ]);
        } catch (\JsonException $exception) {
            return $this->jsonRpcError($id, -32700, 'Parse error.', Response::HTTP_BAD_REQUEST);
        } catch (BadRequestHttpException $exception) {
            if ($exception->getPrevious() instanceof \JsonException) {
                return $this->jsonRpcError($id, -32700, 'Parse error.', Response::HTTP_BAD_REQUEST);
            }

            return $this->jsonRpcError($id, -32602, $exception->getMessage(), Response::HTTP_BAD_REQUEST);
        } catch (ValidationException $exception) {
            return $this->jsonRpcError($id, -32602, $exception->getMessage(), Response::HTTP_BAD_REQUEST);
        } catch (NegotiationException $exception) {
            return $this->jsonRpcError($id, -32602, $exception->getMessage(), Response::HTTP_BAD_REQUEST);
        } catch (UnsupportedCapabilityException $exception) {
            return $this->jsonRpcError($id, -32601, $exception->getMessage(), Response::HTTP_NOT_FOUND);
        }
    }

    private function requireTransport(RuntimeConfiguration $runtimeConfiguration): void
    {
        if (! in_array(Transport::A2a, $runtimeConfiguration->transports, true)) {
            throw new NotFoundHttpException('A2A transport is not enabled.');
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonRpcId(array $payload): int|string|null
    {
        $id = $payload['id'] ?? null;
        if ($id !== null && ! is_int($id) && ! is_string($id)) {
            throw new BadRequestHttpException('JSON-RPC id must be a string, integer, or null.');
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonRpcMethod(array $payload): string
    {
        if (($payload['jsonrpc'] ?? null) !== '2.0') {
            throw new BadRequestHttpException('JSON-RPC version must be "2.0".');
        }

        $method = $payload['method'] ?? null;
        if (! is_string($method) || $method === '') {
            throw new BadRequestHttpException('JSON-RPC method must be a non-empty string.');
        }

        return $method;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function jsonRpcParams(array $payload): array
    {
        $params = $payload['params'] ?? [];
        if (! is_array($params) || (array_is_list($params) && $params !== [])) {
            throw new BadRequestHttpException('JSON-RPC params must be an object.');
        }

        return $params;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function payloadParams(array $params): array
    {
        unset($params['id']);

        return $params;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function optionalId(array $params): ?string
    {
        if (! array_key_exists('id', $params)) {
            return null;
        }

        $id = $params['id'] ?? null;
        if (! is_string($id) || $id === '') {
            throw new BadRequestHttpException('A2A operation requires a non-empty string id parameter.');
        }

        return $id;
    }

    private function jsonRpcError(int|string|null $id, int $code, string $message, int $statusCode): JsonResponse
    {
        return new JsonResponse([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $statusCode);
    }

    private function toHttpRequest(Request $request): HttpRequest
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $value) {
            $headers[$name] = implode(', ', array_map(static fn (?string $entry): string => (string) $entry, $value));
        }

        $query = $request->query->all();
        ksort($query);

        return new HttpRequest(
            $request->getMethod(),
            $request->getUri(),
            $headers,
            array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR), $query),
            '',
        );
    }
}
