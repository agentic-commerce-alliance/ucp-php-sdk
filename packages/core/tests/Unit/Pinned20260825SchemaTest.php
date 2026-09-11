<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Internal\Validation\GeneratedSchemaValidator;

/**
 * The 2026-08-25 schema set, pinned but not yet served.
 *
 * Its value before the version flip is that it settles what the upgrade actually has to absorb.
 * Reading a release note is not the same as reading the schemas: one of the breaking changes
 * this repository had planned for turned out not to exist in the shape the note implied, and
 * this is where that was caught.
 */
final class Pinned20260825SchemaTest extends TestCase
{
    private const VERSION = '2026-08-25';

    #[Test]
    public function everyOperationHasARequestAndResponseSchema(): void
    {
        $validator = $this->validator(self::VERSION);

        foreach ([
            'catalog.search', 'catalog.lookup', 'catalog.product',
            'cart.create', 'cart.get', 'cart.update', 'cart.cancel',
            'discount.apply',
            'checkout.create', 'checkout.get', 'checkout.update', 'checkout.complete', 'checkout.cancel',
            'order.get',
        ] as $operation) {
            foreach (['request', 'response'] as $direction) {
                // Loading is the assertion: the validator throws for a schema that is absent or
                // not decodable, so an empty payload reaching a violation means it parsed.
                try {
                    $validator->validate($operation . '.' . $direction, []);
                } catch (ValidationException $exception) {
                    self::assertStringNotContainsString('does not exist', $exception->getMessage());
                }
            }
        }

        foreach (['tokenization.request', 'tokenization.response'] as $schema) {
            try {
                $validator->validate($schema, []);
            } catch (ValidationException $exception) {
                self::assertStringNotContainsString('does not exist', $exception->getMessage());
            }
        }
    }

    /**
     * Breaking change #5: `cart.id` is omitted in update requests rather than required.
     *
     * The id travels on the transport -- a REST path segment, an MCP tool argument -- and
     * requiring it in the body as well made every client send it twice.
     */
    #[Test]
    public function cartUpdateNoLongerRequiresTheIdInTheBody(): void
    {
        $payload = ['line_items' => []];

        $this->validator(self::VERSION)->validate('cart.update.request', $payload);

        $this->expectException(ValidationException::class);
        $this->validator('2026-04-08')->validate('cart.update.request', $payload);
    }

    /**
     * Not the change the release note implied, and worth pinning so it is not re-planned.
     *
     * "Fractional quantities" does not make `quantity` fractional. It stays an integer, and its
     * description says so: "Always an integer step count." What varies is the *unit* a step is
     * denominated in, through `item.quantity_unit` (absent means `each`), with `measure` used by
     * `unit_price` for the per-unit reference. So weight-priced goods are 25 steps of 100g, not
     * a quantity of 2.5 -- which means no value object and no break on `LineItem::$quantity`.
     */
    #[Test]
    public function quantityIsStillAnIntegerStepCount(): void
    {
        $schema = $this->generatedSchema(self::VERSION, 'cart.create.request');
        $quantity = $schema['properties']['line_items']['items']['properties']['quantity'];

        self::assertSame('integer', $quantity['type']);
        self::assertArrayNotHasKey('anyOf', $quantity);

        $this->validator(self::VERSION)->validate('cart.create.request', [
            'line_items' => [['item' => ['id' => 'sku-1'], 'quantity' => 25]],
        ]);
    }

    private function validator(string $version): GeneratedSchemaValidator
    {
        return new GeneratedSchemaValidator(dirname(__DIR__, 2) . '/resources/schema/generated/' . $version);
    }

    /**
     * @return array<string, mixed>
     */
    private function generatedSchema(string $version, string $name): array
    {
        $path = dirname(__DIR__, 2) . '/resources/schema/generated/' . $version . '/' . $name . '.json';
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
