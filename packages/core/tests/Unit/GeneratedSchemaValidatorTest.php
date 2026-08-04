<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Internal\Validation\GeneratedSchemaValidator;

final class GeneratedSchemaValidatorTest extends TestCase
{
    /** @var list<string> */
    private array $temporarySchemaDirectories = [];

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->temporarySchemaDirectories as $directory) {
            @unlink($directory . '/custom.json');
            @rmdir($directory);
        }
    }

    public function testItValidatesRequiredFields(): void
    {
        $validator = new GeneratedSchemaValidator(dirname(__DIR__, 2) . '/resources/schema/generated/2026-04-08');
        $validator->validate('catalog.search.request', ['query' => 'shoes']);

        $this->expectNotToPerformAssertions();
    }

    public function testItRejectsInvalidRequiredFields(): void
    {
        $validator = new GeneratedSchemaValidator(dirname(__DIR__, 2) . '/resources/schema/generated/2026-04-08');

        $this->expectException(ValidationException::class);
        $validator->validate('catalog.lookup.request', []);
    }

    public function testCheckoutCreateAcceptsLineItemsOrCartId(): void
    {
        $validator = new GeneratedSchemaValidator(dirname(__DIR__, 2) . '/resources/schema/generated/2026-04-08');

        // line_items alone (plain checkout create).
        $validator->validate('checkout.create.request', ['line_items' => []]);
        // cart_id alone (cart-to-checkout conversion — the cart supplies line_items).
        $validator->validate('checkout.create.request', ['cart_id' => 'cart-1']);
        // both present is allowed too: cart.json says overlapping fields are ignored, not rejected
        // (this is why the schema uses anyOf, not oneOf).
        $validator->validate('checkout.create.request', ['cart_id' => 'cart-1', 'line_items' => []]);

        $this->expectNotToPerformAssertions();
    }

    public function testCheckoutCreateRejectsWhenNeitherLineItemsNorCartIdPresent(): void
    {
        $validator = new GeneratedSchemaValidator(dirname(__DIR__, 2) . '/resources/schema/generated/2026-04-08');

        $this->expectException(ValidationException::class);
        $validator->validate('checkout.create.request', ['buyer' => ['email' => 'buyer@example.com']]);
    }

    public function testItProvidesSchemasForEveryShoppingOperation(): void
    {
        $validator = new GeneratedSchemaValidator(dirname(__DIR__, 2) . '/resources/schema/generated/2026-04-08');

        foreach ($this->shoppingOperationPayloads() as $schemaName => $payload) {
            $validator->validate($schemaName, $payload);
        }

        $this->expectNotToPerformAssertions();
    }

    public function testCatalogProductRequestSchemaContainsSpecBodyFields(): void
    {
        $schema = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/resources/schema/generated/2026-04-08/catalog.product.request.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(['id'], $schema['required'] ?? null);
        self::assertSame([
            'id',
            'selected',
            'preferences',
            'filters',
            'context',
            'signals',
            'attribution',
        ], array_keys($schema['properties'] ?? []));
    }

    public function testItValidatesAdditionalSchemaKeywords(): void
    {
        $directory = $this->createTemporarySchemaDirectory();
        file_put_contents($directory . '/custom.json', json_encode([
            'type' => 'object',
            'required' => ['status', 'items'],
            'additionalProperties' => false,
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['ok', 'pending']],
                'items' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => [
                        'type' => 'object',
                        'required' => ['sku'],
                        'properties' => [
                            'sku' => ['type' => 'string', 'pattern' => '^[A-Z0-9-]+$'],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $validator = new GeneratedSchemaValidator($directory);
        $validator->validate('custom', [
            'status' => 'ok',
            'items' => [
                ['sku' => 'SKU-1'],
            ],
        ]);

        $this->expectNotToPerformAssertions();
    }

    public function testItRejectsEnumPatternAndAdditionalPropertyViolations(): void
    {
        $directory = $this->createTemporarySchemaDirectory();
        file_put_contents($directory . '/custom.json', json_encode([
            'type' => 'object',
            'required' => ['status'],
            'additionalProperties' => false,
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['ok']],
                'code' => ['type' => 'string', 'pattern' => '^ABC$'],
            ],
        ], JSON_THROW_ON_ERROR));

        $validator = new GeneratedSchemaValidator($directory);

        $this->expectException(ValidationException::class);
        $validator->validate('custom', [
            'status' => 'bad',
            'code' => 'XYZ',
            'unexpected' => true,
        ]);
    }

    public function testItValidatesOneOfAndStringFormats(): void
    {
        $directory = $this->createTemporarySchemaDirectory();
        file_put_contents($directory . '/custom.json', json_encode([
            'oneOf' => [
                [
                    'type' => 'object',
                    'required' => ['email'],
                    'properties' => [
                        'email' => ['type' => 'string', 'format' => 'email'],
                    ],
                    'additionalProperties' => false,
                ],
                [
                    'type' => 'object',
                    'required' => ['callback'],
                    'properties' => [
                        'callback' => ['type' => 'string', 'format' => 'uri'],
                    ],
                    'additionalProperties' => false,
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $validator = new GeneratedSchemaValidator($directory);
        $validator->validate('custom', ['callback' => 'https://example.test/callback']);

        $this->expectNotToPerformAssertions();
    }

    public function testItRejectsValuesThatDoNotMatchOneOfVariants(): void
    {
        $directory = $this->createTemporarySchemaDirectory();
        file_put_contents($directory . '/custom.json', json_encode([
            'oneOf' => [
                [
                    'type' => 'object',
                    'required' => ['email'],
                    'properties' => [
                        'email' => ['type' => 'string', 'format' => 'email'],
                    ],
                    'additionalProperties' => false,
                ],
                [
                    'type' => 'object',
                    'required' => ['count'],
                    'properties' => [
                        'count' => ['type' => 'integer', 'minimum' => 1],
                    ],
                    'additionalProperties' => false,
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $validator = new GeneratedSchemaValidator($directory);

        $this->expectException(ValidationException::class);
        $validator->validate('custom', ['email' => 'not-an-email']);
    }

    public function testItRejectsCatalogProductResponsesWithoutProtocolEnvelope(): void
    {
        $validator = new GeneratedSchemaValidator(dirname(__DIR__, 2) . '/resources/schema/generated/2026-04-08');

        $this->expectException(ValidationException::class);
        $validator->validate('catalog.product.response', [
            'id' => 'sku-1',
            'title' => 'Tent',
            'price' => 10.0,
        ]);
    }

    public function testItRejectsCartResponsesWithoutProtocolEnvelope(): void
    {
        $validator = new GeneratedSchemaValidator(dirname(__DIR__, 2) . '/resources/schema/generated/2026-04-08');

        $this->expectException(ValidationException::class);
        $validator->validate('cart.create.response', [
            'id' => 'cart-1',
            'line_items' => [],
            'currency' => 'EUR',
            'totals' => ['total' => ['amount' => 1000, 'currency' => 'EUR']],
        ]);
    }

    public function testItRejectsCheckoutResponsesWithUnknownStatus(): void
    {
        $validator = new GeneratedSchemaValidator(dirname(__DIR__, 2) . '/resources/schema/generated/2026-04-08');

        $this->expectException(ValidationException::class);
        $validator->validate('checkout.create.response', [
            ...$this->validCheckoutResponse(),
            'status' => 'done',
        ]);
    }

    public function testItRejectsCartResponsesWithoutSubtotalAndTotalRows(): void
    {
        $validator = new GeneratedSchemaValidator(dirname(__DIR__, 2) . '/resources/schema/generated/2026-04-08');

        $this->expectException(ValidationException::class);
        $validator->validate('cart.create.response', [
            ...$this->validCartResponse(),
            'totals' => [
                ['type' => 'total', 'amount' => 1000],
            ],
        ]);
    }

    public function testItRejectsResponseCapabilityNamesThatAreNotReverseDomains(): void
    {
        $validator = new GeneratedSchemaValidator(dirname(__DIR__, 2) . '/resources/schema/generated/2026-04-08');

        $this->expectException(ValidationException::class);
        $validator->validate('catalog.product.response', [
            'ucp' => $this->ucpEnvelope('catalog'),
            'product' => $this->validProduct(),
        ]);
    }

    public function testItRejectsEmptyProductDescriptions(): void
    {
        $validator = new GeneratedSchemaValidator(dirname(__DIR__, 2) . '/resources/schema/generated/2026-04-08');

        $this->expectException(ValidationException::class);
        $validator->validate('catalog.product.response', [
            'ucp' => $this->ucpEnvelope('dev.ucp.shopping.catalog.lookup'),
            'product' => [
                ...$this->validProduct(),
                'description' => [],
            ],
        ]);
    }

    private function createTemporarySchemaDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/ucp-sdk-schema-' . bin2hex(random_bytes(4));
        mkdir($directory);
        $this->temporarySchemaDirectories[] = $directory;

        return $directory;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function shoppingOperationPayloads(): array
    {
        $cart = $this->validCartResponse();
        $checkout = $this->validCheckoutResponse();

        return [
            'catalog.search.request' => ['query' => 'tent'],
            'catalog.search.response' => [
                'ucp' => $this->ucpEnvelope('dev.ucp.shopping.catalog.search'),
                'products' => [],
            ],
            'catalog.lookup.request' => ['ids' => ['sku-1']],
            'catalog.lookup.response' => [
                'ucp' => $this->ucpEnvelope('dev.ucp.shopping.catalog.lookup'),
                'products' => [],
            ],
            'catalog.product.request' => ['id' => 'sku-1'],
            'catalog.product.response' => [
                'ucp' => $this->ucpEnvelope('dev.ucp.shopping.catalog.lookup'),
                'product' => $this->validProduct(),
            ],
            'cart.create.request' => ['line_items' => []],
            'cart.create.response' => $cart,
            'cart.get.request' => ['id' => 'cart-1'],
            'cart.get.response' => $cart,
            'cart.update.request' => ['id' => 'cart-1', 'line_items' => []],
            'cart.update.response' => $cart,
            'cart.cancel.request' => ['id' => 'cart-1'],
            'cart.cancel.response' => $cart,
            'discount.apply.request' => ['cart_id' => 'cart-1', 'code' => 'SAVE10'],
            'discount.apply.response' => $cart,
            'checkout.create.request' => ['line_items' => []],
            'checkout.create.response' => $checkout,
            'checkout.get.request' => ['id' => 'checkout-1'],
            'checkout.get.response' => $checkout,
            'checkout.update.request' => ['line_items' => []],
            'checkout.update.response' => $checkout,
            'checkout.complete.request' => ['payment' => ['instruments' => []]],
            'checkout.complete.response' => [
                ...$checkout,
                'status' => 'completed',
            ],
            'checkout.cancel.request' => ['id' => 'checkout-1'],
            'checkout.cancel.response' => [
                ...$checkout,
                'status' => 'canceled',
            ],
            'order.get.request' => ['id' => 'order-1'],
            'order.get.response' => [
                'ucp' => $this->ucpEnvelope('dev.ucp.shopping.order'),
                'id' => 'order-1',
                'checkout_id' => 'checkout-1',
                'permalink_url' => 'https://merchant.example/orders/order-1',
                'currency' => 'EUR',
                'line_items' => [],
                'fulfillment' => [],
                'totals' => $this->validTotals(),
                'messages' => [],
                'links' => [],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validCartResponse(): array
    {
        return [
            'ucp' => $this->ucpEnvelope('dev.ucp.shopping.cart'),
            'id' => 'cart-1',
            'line_items' => [],
            'currency' => 'EUR',
            'totals' => $this->validTotals(),
            'messages' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validCheckoutResponse(): array
    {
        return [
            'ucp' => $this->ucpEnvelope('dev.ucp.shopping.checkout'),
            'id' => 'checkout-1',
            'status' => 'incomplete',
            'currency' => 'EUR',
            'line_items' => [],
            'totals' => $this->validTotals(),
            'messages' => [],
            'links' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validProduct(): array
    {
        return [
            'id' => 'sku-1',
            'title' => 'Tent',
            'description' => ['plain' => 'Canvas tent.'],
            'price_range' => [
                'min' => ['amount' => 1000, 'currency' => 'EUR'],
                'max' => ['amount' => 1000, 'currency' => 'EUR'],
            ],
            'variants' => [
                [
                    'id' => 'variant-1',
                    'title' => 'Default',
                    'description' => ['plain' => 'Default variant.'],
                    'price' => ['amount' => 1000, 'currency' => 'EUR'],
                    'availability' => ['available' => true],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ucpEnvelope(string $capability): array
    {
        return [
            'version' => '2026-04-08',
            'status' => 'success',
            'capabilities' => [
                $capability => [
                    ['version' => '2026-04-08'],
                ],
            ],
            'payment_handlers' => [],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validTotals(): array
    {
        return [
            ['type' => 'subtotal', 'amount' => 1000],
            ['type' => 'total', 'amount' => 1000],
        ];
    }
}
