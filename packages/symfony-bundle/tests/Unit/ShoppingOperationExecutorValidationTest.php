<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Ucp\Sdk\Contract\Ap2CheckoutMandateVerifierInterface;
use Ucp\Sdk\Contract\CapabilityInterface;
use Ucp\Sdk\Contract\CartCapabilityInterface;
use Ucp\Sdk\Contract\CatalogCapabilityInterface;
use Ucp\Sdk\Contract\CheckoutCapabilityInterface;
use Ucp\Sdk\Contract\DiscountCapabilityInterface;
use Ucp\Sdk\Contract\OrderCapabilityInterface;
use Ucp\Sdk\Enum\CheckoutStatus;
use Ucp\Sdk\Exception\NegotiationException;
use Ucp\Sdk\Model\Cart\Cart;
use Ucp\Sdk\Model\Cart\CartCreateRequest;
use Ucp\Sdk\Model\Cart\CartUpdateRequest;
use Ucp\Sdk\Model\Catalog\CatalogLookupRequest;
use Ucp\Sdk\Model\Catalog\CatalogProductRequest;
use Ucp\Sdk\Model\Catalog\CatalogSearchRequest;
use Ucp\Sdk\Model\Catalog\CatalogSearchResponse;
use Ucp\Sdk\Model\Catalog\Product;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Checkout\CheckoutCompleteRequest;
use Ucp\Sdk\Model\Checkout\CheckoutCreateRequest;
use Ucp\Sdk\Model\Checkout\CheckoutUpdateRequest;
use Ucp\Sdk\Model\Checkout\DiscountCode;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\Negotiation\NegotiatedCapabilities;
use Ucp\Sdk\Model\Order\OrderView;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Service\CapabilityRegistryInterface;
use Ucp\Sdk\Service\ProtocolValidatorInterface;
use Ucp\Sdk\Symfony\Bridge\HttpPayloadMapper;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationExecutor;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationRequest;

final class ShoppingOperationExecutorValidationTest extends TestCase
{
    #[Test]
    public function itValidatesRequestsAndResponsesForEveryShoppingOperation(): void
    {
        $validator = new ShoppingOperationProtocolValidatorSpy();
        $executor = new ShoppingOperationExecutor(
            new ShoppingOperationCapabilityRegistryFake(new ShoppingOperationCapabilityFake()),
            $validator,
            new HttpPayloadMapper(),
            [],
            [],
            [],
            new EventDispatcher(),
        );
        $context = new RequestContext('merchant.example');

        foreach ($this->operationRequests($context) as $request) {
            self::assertArrayHasKey('ucp', $executor->execute($request)->toArray());
        }

        self::assertSame([
            'request:catalog.search',
            'response:catalog.search',
            'request:catalog.lookup',
            'response:catalog.lookup',
            'request:catalog.product',
            'response:catalog.product',
            'request:cart.create',
            'response:cart.create',
            'request:cart.get',
            'response:cart.get',
            'request:cart.update',
            'response:cart.update',
            'request:cart.cancel',
            'response:cart.cancel',
            'request:discount.apply',
            'response:discount.apply',
            'request:checkout.create',
            'response:checkout.create',
            'request:checkout.get',
            'response:checkout.get',
            'request:checkout.update',
            'response:checkout.update',
            'request:checkout.complete',
            'response:checkout.complete',
            'request:checkout.cancel',
            'response:checkout.cancel',
            'request:order.get',
            'response:order.get',
        ], $validator->calls);
    }

    #[Test]
    public function itRejectsRequestsFromUnsupportedProfileVersionsBeforeExecutingOperations(): void
    {
        $validator = new ShoppingOperationProtocolValidatorSpy();
        $executor = new ShoppingOperationExecutor(
            new ShoppingOperationCapabilityRegistryFake(new ShoppingOperationCapabilityFake()),
            $validator,
            new HttpPayloadMapper(),
            [],
            [],
            [],
            new EventDispatcher(),
        );
        $context = new RequestContext(
            'merchant.example',
            platformProfile: new PlatformProfile('2025-10-01', [], [], []),
            runtimeConfiguration: new RuntimeConfiguration(
                '2026-04-08',
                'https://merchant.example',
                supportedVersions: ['2026-03-01' => 'https://merchant.example/.well-known/ucp/2026-03-01'],
            ),
            negotiation: new NegotiatedCapabilities([
                'dev.ucp.shopping' => [
                    new CapabilityDescriptor('dev.ucp.shopping', '2026-04-08', 'spec', 'schema'),
                ],
            ], operationCapabilityMap: [
                'catalog.search' => ['dev.ucp.shopping'],
            ]),
        );

        try {
            $executor->execute(new ShoppingOperationRequest('catalog.search', ['query' => 'tent'], $context));
            self::fail('Expected unsupported version rejection.');
        } catch (NegotiationException $exception) {
            self::assertSame('version_unsupported', $exception->errorCode);
        }

        self::assertSame([], $validator->calls);
    }

    #[Test]
    public function itRejectsRequestsWhenTheOperationCapabilityWasNotNegotiated(): void
    {
        $validator = new ShoppingOperationProtocolValidatorSpy();
        $executor = new ShoppingOperationExecutor(
            new ShoppingOperationCapabilityRegistryFake(new ShoppingOperationCapabilityFake()),
            $validator,
            new HttpPayloadMapper(),
            [],
            [],
            [],
            new EventDispatcher(),
        );
        $context = new RequestContext(
            'merchant.example',
            platformProfile: new PlatformProfile('2026-04-08', [], [], []),
            runtimeConfiguration: new RuntimeConfiguration('2026-04-08', 'https://merchant.example'),
            negotiation: new NegotiatedCapabilities([
                'dev.ucp.shopping.cart' => [
                    new CapabilityDescriptor('dev.ucp.shopping.cart', '2026-04-08', 'spec', 'schema'),
                ],
            ], operationCapabilityMap: [
                'cart.create' => ['dev.ucp.shopping.cart'],
            ]),
        );

        try {
            $executor->execute(new ShoppingOperationRequest('catalog.search', ['query' => 'tent'], $context));
            self::fail('Expected incompatible capability rejection.');
        } catch (NegotiationException $exception) {
            self::assertSame('capabilities_incompatible', $exception->errorCode);
        }

        self::assertSame([], $validator->calls);
    }

    #[Test]
    public function itReturnsTypedOperationResponsesWithProtocolEnvelopeMetadata(): void
    {
        $executor = new ShoppingOperationExecutor(
            new ShoppingOperationCapabilityRegistryFake(new ShoppingOperationCapabilityFake()),
            new ShoppingOperationProtocolValidatorSpy(),
            new HttpPayloadMapper(),
            [],
            [],
            [],
            new EventDispatcher(),
        );

        $response = $executor->execute(new ShoppingOperationRequest(
            'catalog.product',
            [],
            new RequestContext('merchant.example'),
            'sku-1',
        ));

        $payload = $response->toArray();
        self::assertIsArray($payload['product']);
        self::assertSame('sku-1', $payload['product']['id']);
        self::assertIsArray($payload['ucp']);
        self::assertIsArray($payload['ucp']['capabilities']);
        self::assertSame('dev.ucp.shopping.catalog.product', array_key_first($payload['ucp']['capabilities']));
    }

    #[Test]
    public function itMapsCatalogProductPayloadIntoDetailRequest(): void
    {
        $capability = new ShoppingOperationCapabilityFake();
        $validator = new ShoppingOperationProtocolValidatorSpy();
        $executor = new ShoppingOperationExecutor(
            new ShoppingOperationCapabilityRegistryFake($capability),
            $validator,
            new HttpPayloadMapper(),
            [],
            [],
            [],
            new EventDispatcher(),
        );
        $context = new RequestContext('merchant.example');

        $executor->execute(new ShoppingOperationRequest('catalog.product', [
            'id' => 'sku-1',
            'selected' => [['name' => 'Color', 'label' => 'Blue']],
            'filters' => ['price' => ['max' => 15000]],
            'preferences' => ['Color', 'Size'],
            'context' => ['address_country' => 'US'],
            'signals' => ['dev.ucp.user_agent' => 'agent'],
            'attribution' => ['utm_source' => 'assistant'],
        ], $context));

        self::assertInstanceOf(CatalogProductRequest::class, $capability->productRequest);
        self::assertSame('sku-1', $capability->productRequest->id);
        self::assertSame([['name' => 'Color', 'label' => 'Blue']], $capability->productRequest->selected);
        self::assertSame(['price' => ['max' => 15000]], $capability->productRequest->filters);
        self::assertSame(['Color', 'Size'], $capability->productRequest->preferences);
        self::assertSame(['address_country' => 'US'], $capability->productRequest->context);
        self::assertSame(['dev.ucp.user_agent' => 'agent'], $capability->productRequest->signals);
        self::assertSame(['utm_source' => 'assistant'], $capability->productRequest->attribution);
        self::assertSame([
            'request:catalog.product',
            'response:catalog.product',
        ], $validator->calls);
    }

    #[Test]
    public function checkoutCompletePassesParsedPaymentAndAp2Request(): void
    {
        $capability = new ShoppingOperationCapabilityFake();
        $executor = new ShoppingOperationExecutor(
            new ShoppingOperationCapabilityRegistryFake($capability),
            new ShoppingOperationProtocolValidatorSpy(),
            new HttpPayloadMapper(),
            [],
            [],
            [],
            new EventDispatcher(),
        );

        $executor->execute(new ShoppingOperationRequest(
            'checkout.complete',
            [
                'payment' => ['instruments' => [[
                    'type' => 'tokenized',
                    'handler_id' => 'com.example.psp',
                    'credential' => ['token' => 'payment_mandate'],
                ]]],
                'ap2' => ['checkout_mandate' => 'checkout_mandate'],
            ],
            new RequestContext('merchant.example'),
            'checkout-1',
        ));

        $completedRequest = $capability->completedRequest;
        self::assertNotNull($completedRequest);
        self::assertSame('checkout-1', $completedRequest->id);
        self::assertSame('checkout_mandate', $completedRequest->ap2?->checkoutMandate);
        self::assertSame('com.example.psp', $completedRequest->payment?->instruments[0]->handlerId);
    }

    #[Test]
    public function checkoutCompleteInvokesAp2VerifierBeforeAdapterCompletion(): void
    {
        $capability = new ShoppingOperationCapabilityFake();
        $verifier = new RecordingAp2CheckoutMandateVerifier($capability);
        $executor = new ShoppingOperationExecutor(
            new ShoppingOperationCapabilityRegistryFake($capability),
            new ShoppingOperationProtocolValidatorSpy(),
            new HttpPayloadMapper(),
            [],
            [],
            [],
            new EventDispatcher(),
            [$verifier],
        );

        $executor->execute(new ShoppingOperationRequest(
            'checkout.complete',
            ['ap2' => ['checkout_mandate' => 'checkout_mandate']],
            new RequestContext('merchant.example'),
            'checkout-1',
        ));

        self::assertSame('checkout-1', $verifier->request?->id);
        self::assertSame('checkout-1', $verifier->currentCheckout?->id);
        self::assertTrue($verifier->calledBeforeAdapterCompletion);
    }

    /**
     * @return list<ShoppingOperationRequest>
     */
    private function operationRequests(RequestContext $context): array
    {
        return [
            new ShoppingOperationRequest('catalog.search', ['query' => 'tent'], $context),
            new ShoppingOperationRequest('catalog.lookup', ['ids' => ['sku-1']], $context),
            new ShoppingOperationRequest('catalog.product', ['id' => 'sku-1'], $context),
            new ShoppingOperationRequest('cart.create', ['line_items' => []], $context),
            new ShoppingOperationRequest('cart.get', [], $context, 'cart-1'),
            new ShoppingOperationRequest('cart.update', ['line_items' => []], $context, 'cart-1'),
            new ShoppingOperationRequest('cart.cancel', [], $context, 'cart-1'),
            new ShoppingOperationRequest('discount.apply', ['cart_id' => 'cart-1', 'code' => 'SAVE10'], $context),
            new ShoppingOperationRequest('checkout.create', ['line_items' => []], $context),
            new ShoppingOperationRequest('checkout.get', [], $context, 'checkout-1'),
            new ShoppingOperationRequest('checkout.update', ['line_items' => []], $context, 'checkout-1'),
            new ShoppingOperationRequest('checkout.complete', [], $context, 'checkout-1'),
            new ShoppingOperationRequest('checkout.cancel', [], $context, 'checkout-1'),
            new ShoppingOperationRequest('order.get', [], $context, 'order-1'),
        ];
    }
}

final class RecordingAp2CheckoutMandateVerifier implements Ap2CheckoutMandateVerifierInterface
{
    public ?CheckoutCompleteRequest $request = null;

    public ?Checkout $currentCheckout = null;

    public bool $calledBeforeAdapterCompletion = false;

    public function __construct(private readonly ShoppingOperationCapabilityFake $capability)
    {
    }

    public function verify(CheckoutCompleteRequest $request, Checkout $currentCheckout, RequestContext $context): void
    {
        $this->request = $request;
        $this->currentCheckout = $currentCheckout;
        $this->calledBeforeAdapterCompletion = $this->capability->completedRequest === null;
    }
}

final class ShoppingOperationProtocolValidatorSpy implements ProtocolValidatorInterface
{
    /** @var list<string> */
    public array $calls = [];

    public function validateRequest(string $operation, array $payload, RequestContext $context): void
    {
        $this->calls[] = 'request:' . $operation;
    }

    public function validateResponse(string $operation, array $payload, RequestContext $context): void
    {
        $this->calls[] = 'response:' . $operation;
    }
}

final class ShoppingOperationCapabilityRegistryFake implements CapabilityRegistryInterface
{
    public function __construct(private CapabilityInterface $capability)
    {
    }

    public function all(): array
    {
        return [$this->capability];
    }

    public function find(string $name): ?CapabilityInterface
    {
        return $this->capability->describe()->name === $name ? $this->capability : null;
    }

    public function firstImplementing(string $interface): ?CapabilityInterface
    {
        return $this->capability instanceof $interface ? $this->capability : null;
    }
}

final class ShoppingOperationCapabilityFake implements CatalogCapabilityInterface, CartCapabilityInterface, DiscountCapabilityInterface, CheckoutCapabilityInterface, OrderCapabilityInterface
{
    public ?CatalogProductRequest $productRequest = null;

    public ?CheckoutCompleteRequest $completedRequest = null;

    public function describe(): CapabilityDescriptor
    {
        return new CapabilityDescriptor('dev.ucp.shopping', '2026-04-08', 'spec', 'schema');
    }

    public function search(CatalogSearchRequest $request, RequestContext $context): CatalogSearchResponse
    {
        return new CatalogSearchResponse([new Product('sku-search', 'Search Result', 10.0)]);
    }

    public function lookup(CatalogLookupRequest $request, RequestContext $context): array
    {
        return [new Product('sku-lookup', 'Lookup Result', 11.0)];
    }

    public function getProduct(CatalogProductRequest $request, RequestContext $context): Product
    {
        $this->productRequest = $request;

        return new Product($request->id, 'Product Detail', 12.0);
    }

    public function createCart(CartCreateRequest $request, RequestContext $context): Cart
    {
        return $this->cart('cart-created');
    }

    public function getCart(string $id, RequestContext $context): Cart
    {
        return $this->cart($id);
    }

    public function updateCart(CartUpdateRequest $request, RequestContext $context): Cart
    {
        return $this->cart($request->id);
    }

    public function cancelCart(string $id, RequestContext $context): Cart
    {
        return $this->cart($id);
    }

    public function applyCartDiscount(string $cartId, DiscountCode $discount, RequestContext $context): Cart
    {
        return $this->cart($cartId);
    }

    public function createCheckout(CheckoutCreateRequest $request, RequestContext $context): Checkout
    {
        return $this->checkout('checkout-created');
    }

    public function getCheckout(string $id, RequestContext $context): Checkout
    {
        return $this->checkout($id);
    }

    public function updateCheckout(CheckoutUpdateRequest $request, RequestContext $context): Checkout
    {
        return $this->checkout($request->id);
    }

    public function completeCheckout(CheckoutCompleteRequest $request, RequestContext $context): Checkout
    {
        $this->completedRequest = $request;

        return $this->checkout($request->id, CheckoutStatus::Completed);
    }

    public function cancelCheckout(string $id, RequestContext $context): Checkout
    {
        return $this->checkout($id, CheckoutStatus::Canceled);
    }

    public function getOrder(string $id, RequestContext $context): OrderView
    {
        return new OrderView(
            $id,
            'EUR',
            [],
            [],
            checkoutId: 'checkout-1',
            permalinkUrl: 'https://example.com/order/' . $id,
            fulfillment: [],
        );
    }

    private function cart(string $id): Cart
    {
        return new Cart($id, [], 'EUR');
    }

    private function checkout(string $id, CheckoutStatus $status = CheckoutStatus::Incomplete): Checkout
    {
        return new Checkout($id, $status, 'EUR', [], []);
    }
}
