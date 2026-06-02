<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Ucp\Sdk\Contract\CapabilityInterface;
use Ucp\Sdk\Contract\CartCapabilityInterface;
use Ucp\Sdk\Contract\CatalogCapabilityInterface;
use Ucp\Sdk\Contract\TokenizationCapabilityInterface;
use Ucp\Sdk\Exception\UnsupportedCapabilityException;
use Ucp\Sdk\Model\Cart\Cart;
use Ucp\Sdk\Model\Cart\CartCreateRequest;
use Ucp\Sdk\Model\Cart\CartUpdateRequest;
use Ucp\Sdk\Model\Catalog\CatalogLookupRequest;
use Ucp\Sdk\Model\Catalog\CatalogSearchRequest;
use Ucp\Sdk\Model\Catalog\CatalogSearchResponse;
use Ucp\Sdk\Model\Catalog\Product;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Service\CapabilityRegistryInterface;
use Ucp\Sdk\Service\ProtocolValidatorInterface;
use Ucp\Sdk\Symfony\Bridge\HttpPayloadMapper;
use Ucp\Sdk\Symfony\Bridge\UcpResponseFactory;
use Ucp\Sdk\Symfony\Controller\CartController;
use Ucp\Sdk\Symfony\Controller\CatalogController;
use Ucp\Sdk\Symfony\Controller\TokenizationController;
use Ucp\Sdk\Symfony\UcpSdkConfiguration;

final class ShoppingControllerTest extends TestCase
{
    #[Test]
    public function itRunsCartControllerOperations(): void
    {
        $capability = new class () implements CartCapabilityInterface {
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
        };
        $controller = new CartController($this->registry($capability), $this->validator(), new HttpPayloadMapper(), $this->responseFactory());
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
        $capability = new class () implements CatalogCapabilityInterface {
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

            public function getProduct(string $id, RequestContext $context): Product
            {
                return new Product($id, 'Product Detail', 12.0);
            }
        };
        $controller = new CatalogController($this->registry($capability), $this->validator(), new HttpPayloadMapper(), $this->responseFactory());

        $search = $this->payload($controller->search($this->jsonRequest('/ucp/v1/catalog/search', ['query' => 'tent'])));
        self::assertSame('sku-search', $search['items'][0]['id']);
        self::assertSame('next', $search['next_cursor']);

        $lookup = $this->payload($controller->lookup($this->jsonRequest('/ucp/v1/catalog/lookup', ['ids' => ['sku-lookup']])));
        self::assertSame('sku-lookup', $lookup['items'][0]['id']);

        $product = $this->payload($controller->product('sku-detail', $this->jsonRequest('/ucp/v1/catalog/product/sku-detail')));
        self::assertSame('sku-detail', $product['id']);
    }

    #[Test]
    public function itRunsTokenizationController(): void
    {
        $capability = new class () implements TokenizationCapabilityInterface {
            public function describe(): CapabilityDescriptor
            {
                return new CapabilityDescriptor('dev.ucp.payment.tokenization', '2026-04-08', 'spec', 'schema');
            }

            public function tokenize(PaymentInstrument $instrument, RequestContext $context): array
            {
                return ['token' => 'tok_' . $instrument->handlerId];
            }
        };
        $controller = new TokenizationController($this->registry($capability), $this->validator(), new HttpPayloadMapper(), $this->responseFactory());

        $payload = $this->payload($controller($this->jsonRequest('/ucp/v1/tokenize', [
            'type' => 'card',
            'handler_id' => 'demo',
            'credential' => ['card_last4' => '4242'],
        ])));

        self::assertSame('tok_demo', $payload['token']);
    }

    #[Test]
    public function itThrowsWhenShoppingCapabilitiesAreMissing(): void
    {
        $cartController = new CartController($this->registry(null), $this->validator(), new HttpPayloadMapper(), $this->responseFactory());

        $this->expectException(UnsupportedCapabilityException::class);
        $this->expectExceptionMessage('Cart capability is not registered.');

        $cartController->get('missing', $this->jsonRequest('/ucp/v1/carts/missing'));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonRequest(string $path, array $payload = []): Request
    {
        $request = Request::create($path, 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR));
        $request->attributes->set('ucp_request_context', new RequestContext('merchant.example'));

        return $request;
    }

    private function registry(?CapabilityInterface $capability): CapabilityRegistryInterface
    {
        return new class ($capability) implements CapabilityRegistryInterface {
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
        };
    }

    private function validator(): ProtocolValidatorInterface
    {
        return new class () implements ProtocolValidatorInterface {
            public function validateRequest(string $operation, array $payload, RequestContext $context): void
            {
            }

            public function validateResponse(string $operation, array $payload, RequestContext $context): void
            {
            }
        };
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
