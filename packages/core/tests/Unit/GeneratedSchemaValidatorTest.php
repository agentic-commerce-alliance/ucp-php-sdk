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

    public function testItRejectsMissingRequiredFields(): void
    {
        $validator = new GeneratedSchemaValidator(dirname(__DIR__, 2) . '/resources/schema/generated/2026-04-08');

        $this->expectException(ValidationException::class);
        $validator->validate('catalog.search.request', []);
    }

    public function testItProvidesSchemasForEveryShoppingOperation(): void
    {
        $validator = new GeneratedSchemaValidator(dirname(__DIR__, 2) . '/resources/schema/generated/2026-04-08');

        foreach ($this->shoppingOperationPayloads() as $schemaName => $payload) {
            $validator->validate($schemaName, $payload);
        }

        $this->expectNotToPerformAssertions();
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
        $cart = [
            'id' => 'cart-1',
            'line_items' => [],
            'currency' => 'EUR',
            'totals' => [],
            'messages' => [],
        ];
        $checkout = [
            'id' => 'checkout-1',
            'status' => 'incomplete',
            'currency' => 'EUR',
            'line_items' => [],
            'totals' => [],
            'messages' => [],
            'links' => [],
        ];

        return [
            'catalog.search.request' => ['query' => 'tent'],
            'catalog.search.response' => ['items' => []],
            'catalog.lookup.request' => ['ids' => ['sku-1']],
            'catalog.lookup.response' => ['items' => []],
            'catalog.product.request' => ['id' => 'sku-1'],
            'catalog.product.response' => ['id' => 'sku-1', 'title' => 'Tent', 'price' => 10.0],
            'cart.create.request' => ['line_items' => []],
            'cart.create.response' => $cart,
            'cart.get.request' => ['id' => 'cart-1'],
            'cart.get.response' => $cart,
            'cart.update.request' => ['line_items' => []],
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
            'checkout.complete.request' => ['id' => 'checkout-1'],
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
                'id' => 'order-1',
                'currency' => 'EUR',
                'line_items' => [],
                'totals' => [],
                'messages' => [],
                'links' => [],
            ],
        ];
    }
}
