<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Ucp\Sdk\Contract\CartCapabilityInterface;
use Ucp\Sdk\Contract\CatalogCapabilityInterface;
use Ucp\Sdk\Contract\CheckoutCapabilityInterface;
use Ucp\Sdk\Contract\DiscountCapabilityInterface;
use Ucp\Sdk\Contract\OrderCapabilityInterface;
use Ucp\Sdk\Enum\Transport;
use Ucp\Sdk\Exception\UnsupportedCapabilityException;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Checkout\DiscountCode;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\Http\HttpRequest;
use Ucp\Sdk\Model\Profile\ProfileBuildInput;
use Ucp\Sdk\Service\CapabilityRegistryInterface;
use Ucp\Sdk\Service\ProfileBuilderInterface;
use Ucp\Sdk\Service\ProtocolValidatorInterface;
use Ucp\Sdk\Service\RuntimeConfigurationResolverInterface;
use Ucp\Sdk\Symfony\Bridge\HttpPayloadMapper;
use Ucp\Sdk\Symfony\UcpSdkConfiguration;

final readonly class A2aController
{
    public function __construct(
        private CapabilityRegistryInterface $capabilityRegistry,
        private ProtocolValidatorInterface $protocolValidator,
        private HttpPayloadMapper $payloadMapper,
        private ProfileBuilderInterface $profileBuilder,
        private RuntimeConfigurationResolverInterface $runtimeConfigurationResolver,
        private UcpSdkConfiguration $configuration,
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

            $result = match ($method) {
                'catalog.search' => $this->catalogSearch($params, $context),
                'catalog.lookup' => $this->catalogLookup($params, $context),
                'cart.create' => $this->cartCreate($params, $context),
                'cart.get' => $this->cartGet($params, $context),
                'cart.update' => $this->cartUpdate($params, $context),
                'cart.cancel' => $this->cartCancel($params, $context),
                'discount.apply' => $this->discountApply($params, $context),
                'checkout.create' => $this->checkoutCreate($params, $context),
                'checkout.get' => $this->checkoutGet($params, $context),
                'checkout.update' => $this->checkoutUpdate($params, $context),
                'checkout.complete' => $this->checkoutComplete($params, $context),
                'checkout.cancel' => $this->checkoutCancel($params, $context),
                'order.get' => $this->orderGet($params, $context),
                default => throw new UnsupportedCapabilityException(sprintf('A2A method "%s" is not supported.', $method)),
            };

            return new JsonResponse([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => $result,
            ]);
        } catch (\JsonException $exception) {
            return $this->jsonRpcError($id, -32700, 'Parse error.', Response::HTTP_BAD_REQUEST);
        } catch (ValidationException|BadRequestHttpException $exception) {
            return $this->jsonRpcError($id, -32602, $exception->getMessage(), Response::HTTP_BAD_REQUEST);
        } catch (UnsupportedCapabilityException $exception) {
            return $this->jsonRpcError($id, -32601, $exception->getMessage(), Response::HTTP_NOT_FOUND);
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function catalogSearch(array $params, mixed $context): array
    {
        $this->protocolValidator->validateRequest('catalog.search', $params, $context);
        $result = $this->catalog()->search($this->payloadMapper->toCatalogSearchRequest($params), $context)->toArray();
        $this->protocolValidator->validateResponse('catalog.search', $result, $context);

        return $result;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function catalogLookup(array $params, mixed $context): array
    {
        $this->protocolValidator->validateRequest('catalog.lookup', $params, $context);
        $result = ['items' => array_map(static fn ($product): array => $product->toArray(), $this->catalog()->lookup($this->payloadMapper->toCatalogLookupRequest($params), $context))];
        $this->protocolValidator->validateResponse('catalog.lookup', $result, $context);

        return $result;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function cartCreate(array $params, mixed $context): array
    {
        $this->protocolValidator->validateRequest('cart.create', $params, $context);
        $result = $this->cart()->createCart($this->payloadMapper->toCartCreateRequest($params), $context)->toArray();
        $this->protocolValidator->validateResponse('cart.create', $result, $context);

        return $result;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function cartUpdate(array $params, mixed $context): array
    {
        $this->protocolValidator->validateRequest('cart.update', $params, $context);
        $result = $this->cart()->updateCart($this->payloadMapper->toCartUpdateRequest((string) ($params['id'] ?? ''), $params), $context)->toArray();
        $this->protocolValidator->validateResponse('cart.update', $result, $context);

        return $result;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function cartGet(array $params, mixed $context): array
    {
        return $this->cart()->getCart($this->requiredId($params), $context)->toArray();
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function cartCancel(array $params, mixed $context): array
    {
        return $this->cart()->cancelCart($this->requiredId($params), $context)->toArray();
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function checkoutCreate(array $params, mixed $context): array
    {
        $this->protocolValidator->validateRequest('checkout.create', $params, $context);
        $result = $this->checkout()->createCheckout($this->payloadMapper->toCheckoutCreateRequest($params), $context)->toArray();
        $this->protocolValidator->validateResponse('checkout.create', $result, $context);

        return $result;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function checkoutUpdate(array $params, mixed $context): array
    {
        $this->protocolValidator->validateRequest('checkout.update', $params, $context);
        $result = $this->checkout()->updateCheckout($this->payloadMapper->toCheckoutUpdateRequest((string) ($params['id'] ?? ''), $params), $context)->toArray();
        $this->protocolValidator->validateResponse('checkout.update', $result, $context);

        return $result;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function checkoutGet(array $params, mixed $context): array
    {
        return $this->checkout()->getCheckout($this->requiredId($params), $context)->toArray();
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function checkoutComplete(array $params, mixed $context): array
    {
        return $this->checkout()->completeCheckout($this->requiredId($params), $context)->toArray();
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function checkoutCancel(array $params, mixed $context): array
    {
        return $this->checkout()->cancelCheckout($this->requiredId($params), $context)->toArray();
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function orderGet(array $params, mixed $context): array
    {
        return $this->order()->getOrder($this->requiredId($params), $context)->toArray();
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function discountApply(array $params, mixed $context): array
    {
        $cartId = (string) ($params['cart_id'] ?? $params['id'] ?? '');
        $code = (string) ($params['code'] ?? $params['discount_code'] ?? '');
        if ($cartId === '' || $code === '') {
            throw new BadRequestHttpException('A2A discount.apply requires cart_id and code parameters.');
        }

        return $this->discount()->applyCartDiscount($cartId, new DiscountCode($code), $context)->toArray();
    }

    private function catalog(): CatalogCapabilityInterface
    {
        $capability = $this->capabilityRegistry->firstImplementing(CatalogCapabilityInterface::class);
        if (!$capability instanceof CatalogCapabilityInterface) {
            throw new UnsupportedCapabilityException('Catalog capability is not registered.');
        }

        return $capability;
    }

    private function cart(): CartCapabilityInterface
    {
        $capability = $this->capabilityRegistry->firstImplementing(CartCapabilityInterface::class);
        if (!$capability instanceof CartCapabilityInterface) {
            throw new UnsupportedCapabilityException('Cart capability is not registered.');
        }

        return $capability;
    }

    private function checkout(): CheckoutCapabilityInterface
    {
        $capability = $this->capabilityRegistry->firstImplementing(CheckoutCapabilityInterface::class);
        if (!$capability instanceof CheckoutCapabilityInterface) {
            throw new UnsupportedCapabilityException('Checkout capability is not registered.');
        }

        return $capability;
    }

    private function discount(): DiscountCapabilityInterface
    {
        $capability = $this->capabilityRegistry->firstImplementing(DiscountCapabilityInterface::class);
        if (!$capability instanceof DiscountCapabilityInterface) {
            throw new UnsupportedCapabilityException('Discount capability is not registered.');
        }

        return $capability;
    }

    private function order(): OrderCapabilityInterface
    {
        $capability = $this->capabilityRegistry->firstImplementing(OrderCapabilityInterface::class);
        if (!$capability instanceof OrderCapabilityInterface) {
            throw new UnsupportedCapabilityException('Order capability is not registered.');
        }

        return $capability;
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
     */
    private function requiredId(array $params): string
    {
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
