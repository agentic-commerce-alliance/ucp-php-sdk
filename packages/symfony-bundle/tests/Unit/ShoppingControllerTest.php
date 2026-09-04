<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Ucp\Sdk\Contract\CapabilityInterface;
use Ucp\Sdk\Contract\CartCapabilityInterface;
use Ucp\Sdk\Contract\CatalogCapabilityInterface;
use Ucp\Sdk\Contract\CheckoutCapabilityInterface;
use Ucp\Sdk\Contract\OrderCapabilityInterface;
use Ucp\Sdk\Contract\TokenizationCapabilityInterface;
use Ucp\Sdk\Enum\CheckoutStatus;
use Ucp\Sdk\Exception\UnsupportedCapabilityException;
use Ucp\Sdk\Model\Cart\Cart;
use Ucp\Sdk\Model\Cart\CartCreateRequest;
use Ucp\Sdk\Model\Cart\CartUpdateRequest;
use Ucp\Sdk\Model\Catalog\CatalogLookupRequest;
use Ucp\Sdk\Model\Catalog\CatalogProductRequest;
use Ucp\Sdk\Model\Catalog\CatalogSearchRequest;
use Ucp\Sdk\Model\Catalog\CatalogSearchResponse;
use Ucp\Sdk\Model\Catalog\Product;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Checkout\CheckoutCreateRequest;
use Ucp\Sdk\Model\Checkout\CheckoutUpdateRequest;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\Order\OrderView;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Service\CapabilityRegistryInterface;
use Ucp\Sdk\Service\ProtocolValidatorInterface;
use Ucp\Sdk\Symfony\Bridge\HttpPayloadMapper;
use Ucp\Sdk\Symfony\Bridge\UcpResponseFactory;
use Ucp\Sdk\Symfony\Controller\CartController;
use Ucp\Sdk\Symfony\Controller\CatalogController;
use Ucp\Sdk\Symfony\Controller\CheckoutController;
use Ucp\Sdk\Symfony\Controller\OrderController;
use Ucp\Sdk\Symfony\Controller\TokenizationController;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationExecutor;
use Ucp\Sdk\Symfony\UcpSdkConfiguration;

final class ShoppingControllerTest extends TestCase
{
    #[Test]
    public function itRunsCartControllerOperations(): void
    {
        $capability = new ControllerCartCapability();
        $validator = $this->createMock(ProtocolValidatorInterface::class);
        $controller = new CartController(new HttpPayloadMapper(), $this->responseFactory(), $this->executor($capability, $validator));
        $request = $this->jsonRequest('/ucp/v1/carts', [
            'line_items' => [[
                'item' => ['id' => 'sku-1', 'title' => 'Tent', 'price' => 10.0],
                'quantity' => 2,
            ]],
        ]);

        $created = $controller->create($request);
        self::assertSame(201, $created->getStatusCode());
        self::assertSame('cart-created', $this->payload($created)['id']);

        self::assertSame('cart-1', $this->payload($controller->get('cart-1', $this->jsonRequest('/ucp/v1/carts/cart-1')))['id']);
        self::assertSame('cart-2', $this->payload($controller->update('cart-2', $request))['id']);
        self::assertSame('cart-3', $this->payload($controller->cancel('cart-3', $this->jsonRequest('/ucp/v1/carts/cart-3/cancel')))['id']);
    }

    #[Test]
    public function itRunsCatalogControllerOperations(): void
    {
        $capability = new ControllerCatalogCapability();
        $validator = $this->createMock(ProtocolValidatorInterface::class);
        $controller = new CatalogController(new HttpPayloadMapper(), $this->responseFactory(), $this->executor($capability, $validator));

        $search = $this->payload($controller->search($this->jsonRequest('/ucp/v1/catalog/search', ['query' => 'tent'])));
        self::assertSame('sku-search', $search['products'][0]['id']);
        self::assertSame('next', $search['pagination']['cursor']);

        $lookup = $this->payload($controller->lookup($this->jsonRequest('/ucp/v1/catalog/lookup', ['ids' => ['sku-lookup']])));
        self::assertSame('sku-lookup', $lookup['products'][0]['id']);

        $product = $this->payload($controller->product('sku-detail', $this->jsonRequest('/ucp/v1/catalog/product/sku-detail')));
        self::assertSame('sku-detail', $product['product']['id']);
    }

    #[Test]
    public function itMapsCatalogProductRouteIdIntoRequestDto(): void
    {
        $capability = new ControllerCatalogCapability();
        $validator = $this->createMock(ProtocolValidatorInterface::class);
        $controller = new CatalogController(new HttpPayloadMapper(), $this->responseFactory(), $this->executor($capability, $validator));

        $product = $this->payload($controller->product('sku-detail', $this->jsonRequest('/ucp/v1/catalog/product/sku-detail')));

        self::assertSame('sku-detail', $product['product']['id']);
        self::assertInstanceOf(CatalogProductRequest::class, $capability->productRequest);
        self::assertSame('sku-detail', $capability->productRequest->id);
        self::assertSame([], $capability->productRequest->selected);
        self::assertSame([], $capability->productRequest->filters);
        self::assertSame([], $capability->productRequest->preferences);
        self::assertSame([], $capability->productRequest->context);
        self::assertSame([], $capability->productRequest->signals);
        self::assertSame([], $capability->productRequest->attribution);
    }

    #[Test]
    public function itRunsTokenizationController(): void
    {
        $capability = new ControllerTokenizationCapability();
        $validator = $this->createMock(ProtocolValidatorInterface::class);
        $controller = new TokenizationController(new SingleCapabilityRegistry($capability), $validator, new HttpPayloadMapper(), $this->responseFactory());

        $payload = $this->payload($controller($this->jsonRequest('/ucp/v1/tokenize', [
            'type' => 'card',
            'handler_id' => 'demo',
            'credential' => ['card_last4' => '4242'],
        ])));

        self::assertSame('tok_demo', $payload['token']);
    }

    /**
     * Every route on this controller reaches the same executor, and the only thing that
     * distinguishes them is the operation name and whether the path id is forwarded. Those
     * are exactly the two things a copy-pasted method body gets wrong, and neither is
     * visible to the type checker -- `checkout.get` and `checkout.cancel` are both strings
     * that reach a registry, so naming the wrong one dispatches somewhere real.
     */
    #[Test]
    public function itRunsCheckoutControllerOperations(): void
    {
        $capability = new ControllerCheckoutCapability();
        $validator = $this->createMock(ProtocolValidatorInterface::class);
        $controller = new CheckoutController(new HttpPayloadMapper(), $this->responseFactory(), $this->executor($capability, $validator));
        $body = ['line_items' => [[
            'item' => ['id' => 'sku-1', 'title' => 'Tent', 'price' => 10.0],
            'quantity' => 1,
        ]]];

        $created = $controller->create($this->jsonRequest('/ucp/v1/checkout-sessions', $body));
        self::assertSame(201, $created->getStatusCode());
        self::assertSame('checkout-created', $this->payload($created)['id']);

        $got = $controller->get('checkout-1', $this->jsonRequest('/ucp/v1/checkout-sessions/checkout-1'));
        self::assertSame(200, $got->getStatusCode());
        self::assertSame('checkout-1', $this->payload($got)['id']);
        self::assertSame('checkout-1', $capability->gotId, 'The path id must reach the capability.');

        self::assertSame('checkout-2', $this->payload($controller->update('checkout-2', $this->jsonRequest('/ucp/v1/checkout-sessions/checkout-2', $body)))['id']);
        self::assertSame('checkout-3', $this->payload($controller->complete('checkout-3', $this->jsonRequest('/ucp/v1/checkout-sessions/checkout-3/complete', $body)))['id']);

        $canceled = $controller->cancel('checkout-4', $this->jsonRequest('/ucp/v1/checkout-sessions/checkout-4/cancel'));
        self::assertSame('checkout-4', $this->payload($canceled)['id']);
        self::assertSame('checkout-4', $capability->canceledId, 'Cancel must not be dispatched as any other operation.');
    }

    #[Test]
    public function itRunsTheOrderController(): void
    {
        $capability = new ControllerOrderCapability();
        $validator = $this->createMock(ProtocolValidatorInterface::class);
        $controller = new OrderController($this->responseFactory(), $this->executor($capability, $validator));

        $response = $controller->get('order-1', $this->jsonRequest('/ucp/v1/orders/order-1'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('order-1', $this->payload($response)['id']);
        self::assertSame('order-1', $capability->gotId);
    }

    /**
     * `GET /ucp/v1/orders` with no id is routed rather than 404ed, so that an agent
     * calling the collection gets a UCP error descriptor naming what is missing instead
     * of Symfony's HTML. The status has to be 400: the request is malformed, not the
     * order absent, and 404 would tell the agent to stop looking for an order it never
     * named.
     */
    #[Test]
    public function itAnswersAnOrderRequestWithNoIdWithAUcpErrorDescriptor(): void
    {
        $controller = new OrderController(
            $this->responseFactory(),
            $this->executor(new ControllerOrderCapability(), $this->createMock(ProtocolValidatorInterface::class)),
        );

        $response = $controller->missingId($this->jsonRequest('/ucp/v1/orders'));

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('Order id is required.', (string) $response->getContent());
    }

    /**
     * The disabled-by-configuration case is already covered; this is the other one. A host
     * that never registered a tokenization capability and one that switched it off both
     * have to fail, and they fail through different branches -- so passing an empty
     * registry proves the instanceof guard, not the configuration check, is what refused.
     */
    #[Test]
    public function itThrowsWhenTokenizationCapabilityIsNotRegistered(): void
    {
        $controller = new TokenizationController(
            new SingleCapabilityRegistry(null),
            $this->createMock(ProtocolValidatorInterface::class),
            new HttpPayloadMapper(),
            $this->responseFactory(),
        );

        $this->expectException(UnsupportedCapabilityException::class);
        $this->expectExceptionMessage('Tokenization capability is not registered.');

        $controller($this->jsonRequest('/ucp/v1/tokenize', [
            'type' => 'card',
            'handler_id' => 'demo',
            'credential' => ['card_last4' => '4242'],
        ]));
    }

    #[Test]
    public function itThrowsWhenShoppingCapabilitiesAreMissing(): void
    {
        $validator = $this->createMock(ProtocolValidatorInterface::class);
        $cartController = new CartController(new HttpPayloadMapper(), $this->responseFactory(), $this->executor(null, $validator));

        $this->expectException(UnsupportedCapabilityException::class);
        $this->expectExceptionMessage('Cart capability is not registered.');

        $cartController->get('missing', $this->jsonRequest('/ucp/v1/carts/missing'));
    }

    #[Test]
    public function itThrowsWhenShoppingCapabilityIsDisabledByRuntimeConfiguration(): void
    {
        $validator = $this->createMock(ProtocolValidatorInterface::class);
        $cartController = new CartController(new HttpPayloadMapper(), $this->responseFactory(), $this->executor(new ControllerCartCapability(), $validator));
        $context = new RequestContext(
            'merchant.example',
            runtimeConfiguration: new RuntimeConfiguration(
                '2026-04-08',
                'https://merchant.example',
                enabledCapabilities: ['dev.ucp.shopping.catalog'],
            ),
        );

        $this->expectException(UnsupportedCapabilityException::class);
        $this->expectExceptionMessage('Cart capability is disabled by runtime configuration.');

        $cartController->get('disabled', $this->jsonRequest('/ucp/v1/carts/disabled', context: $context));
    }

    #[Test]
    public function itThrowsWhenTokenizationCapabilityIsDisabledByRuntimeConfiguration(): void
    {
        $capability = new ControllerTokenizationCapability();
        $validator = $this->createMock(ProtocolValidatorInterface::class);
        $controller = new TokenizationController(new SingleCapabilityRegistry($capability), $validator, new HttpPayloadMapper(), $this->responseFactory());
        $context = new RequestContext(
            'merchant.example',
            runtimeConfiguration: new RuntimeConfiguration(
                '2026-04-08',
                'https://merchant.example',
                enabledCapabilities: ['dev.ucp.shopping.cart'],
            ),
        );

        $this->expectException(UnsupportedCapabilityException::class);
        $this->expectExceptionMessage('Tokenization capability is disabled by runtime configuration.');

        $controller($this->jsonRequest('/ucp/v1/tokenize', [
            'type' => 'card',
            'handler_id' => 'demo',
            'credential' => ['card_last4' => '4242'],
        ], $context));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonRequest(string $path, array $payload = [], ?RequestContext $context = null): Request
    {
        $request = Request::create($path, 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR));
        $request->attributes->set('ucp_request_context', $context ?? new RequestContext('merchant.example'));

        return $request;
    }

    private function executor(?CapabilityInterface $capability, ProtocolValidatorInterface $validator): ShoppingOperationExecutor
    {
        return new ShoppingOperationExecutor(
            new SingleCapabilityRegistry($capability),
            $validator,
            new HttpPayloadMapper(),
            [],
            [],
            [],
            new EventDispatcher(),
        );
    }

    private function responseFactory(): UcpResponseFactory
    {
        return new UcpResponseFactory(new UcpSdkConfiguration(
            '2026-04-08',
            'https://merchant.example',
            [],
            'log',
            [],
            false,
            86400,
            262144,
            600,
            604800,
            300,
            600,
            [],
            false,
            'default',
            'ES256',
            'P30D',
            'P30D',
            262144,
            10,
            false,
            'sqlite:///%kernel.project_dir%/var/ucp_sdk.sqlite',
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(\Symfony\Component\HttpFoundation\Response $response): array
    {
        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}

final class ControllerCartCapability implements CartCapabilityInterface
{
    public function describe(): CapabilityDescriptor
    {
        return new CapabilityDescriptor('dev.ucp.shopping.cart', '2026-04-08', 'spec', 'schema');
    }

    public function createCart(CartCreateRequest $request, RequestContext $context): Cart
    {
        return new Cart('cart-created', $request->lineItems, 'EUR');
    }

    public function getCart(string $id, RequestContext $context): Cart
    {
        return new Cart($id, [], 'EUR');
    }

    public function updateCart(CartUpdateRequest $request, RequestContext $context): Cart
    {
        return new Cart($request->id, $request->lineItems, 'EUR');
    }

    public function cancelCart(string $id, RequestContext $context): Cart
    {
        return new Cart($id, [], 'EUR');
    }
}

final class ControllerCatalogCapability implements CatalogCapabilityInterface
{
    public ?CatalogProductRequest $productRequest = null;

    public function describe(): CapabilityDescriptor
    {
        return new CapabilityDescriptor('dev.ucp.shopping.catalog', '2026-04-08', 'spec', 'schema');
    }

    public function search(CatalogSearchRequest $request, RequestContext $context): CatalogSearchResponse
    {
        return new CatalogSearchResponse([new Product('sku-search', 'Search Result', 10.0)], 'next');
    }

    public function lookup(CatalogLookupRequest $request, RequestContext $context): array
    {
        return [new Product($request->ids[0] ?? 'sku-lookup', 'Lookup Result', 11.0)];
    }

    public function getProduct(CatalogProductRequest $request, RequestContext $context): Product
    {
        $this->productRequest = $request;

        return new Product($request->id, 'Product Detail', 12.0);
    }
}

final class ControllerCheckoutCapability implements CheckoutCapabilityInterface
{
    public ?string $gotId = null;

    public ?string $canceledId = null;

    public function describe(): CapabilityDescriptor
    {
        return new CapabilityDescriptor('dev.ucp.shopping.checkout', '2026-04-08', 'spec', 'schema');
    }

    public function createCheckout(CheckoutCreateRequest $request, RequestContext $context): Checkout
    {
        return $this->checkout('checkout-created');
    }

    public function getCheckout(string $id, RequestContext $context): Checkout
    {
        $this->gotId = $id;

        return $this->checkout($id);
    }

    public function updateCheckout(CheckoutUpdateRequest $request, RequestContext $context): Checkout
    {
        return $this->checkout($request->id);
    }

    public function completeCheckout(string $id, RequestContext $context): Checkout
    {
        return $this->checkout($id, CheckoutStatus::Completed);
    }

    public function cancelCheckout(string $id, RequestContext $context): Checkout
    {
        $this->canceledId = $id;

        return $this->checkout($id, CheckoutStatus::Canceled);
    }

    private function checkout(string $id, CheckoutStatus $status = CheckoutStatus::Incomplete): Checkout
    {
        return new Checkout($id, $status, 'EUR', [], []);
    }
}

final class ControllerOrderCapability implements OrderCapabilityInterface
{
    public ?string $gotId = null;

    public function describe(): CapabilityDescriptor
    {
        return new CapabilityDescriptor('dev.ucp.shopping.order', '2026-04-08', 'spec', 'schema');
    }

    public function getOrder(string $id, RequestContext $context): OrderView
    {
        $this->gotId = $id;

        return new OrderView($id, 'EUR', [], []);
    }
}

final class ControllerTokenizationCapability implements TokenizationCapabilityInterface
{
    public function describe(): CapabilityDescriptor
    {
        return new CapabilityDescriptor('dev.ucp.payment.tokenization', '2026-04-08', 'spec', 'schema');
    }

    public function tokenize(PaymentInstrument $instrument, RequestContext $context): array
    {
        return ['token' => 'tok_' . $instrument->handlerId];
    }
}

final class SingleCapabilityRegistry implements CapabilityRegistryInterface
{
    public function __construct(private ?CapabilityInterface $capability)
    {
    }

    public function all(): array
    {
        return $this->capability === null ? [] : [$this->capability];
    }

    public function find(string $name): ?CapabilityInterface
    {
        return $this->capability?->describe()->name === $name ? $this->capability : null;
    }

    public function firstImplementing(string $interface): ?CapabilityInterface
    {
        return $this->capability instanceof $interface ? $this->capability : null;
    }
}
