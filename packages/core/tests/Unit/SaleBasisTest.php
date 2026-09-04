<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Model\Common\LineItem;
use Ucp\Sdk\Model\Common\Measure;
use Ucp\Sdk\Model\Common\Unit;
use Ucp\Sdk\Model\Common\UnitPrice;

/**
 * How a fractional purchase is expressed without a fractional quantity.
 *
 * `quantity` is an integer step count and stays one. What makes half a kilo of coffee
 * representable is the *unit* a step is denominated in, carried on the item as `quantity_unit`.
 * Reading "fractional quantities" as "quantity becomes a float" is the mistake this pins
 * against: it would mean widening `LineItem::$quantity` and breaking every caller doing
 * arithmetic on it, for a change the schema does not describe.
 */
final class SaleBasisTest extends TestCase
{
    #[Test]
    public function halfAKiloIsFiveHundredIntegerStepsOfAGram(): void
    {
        $item = new LineItem(
            'coffee-beans',
            'Single Origin Beans',
            0.02,
            500,
            quantityUnit: new Unit('GRM', 'g'),
        );

        $payload = $item->toArray();

        self::assertSame(500, $payload['quantity'], 'still an integer');
        self::assertSame([
            'id' => 'coffee-beans',
            'title' => 'Single Origin Beans',
            'price' => 2,
            'quantity_unit' => ['unit' => 'GRM', 'display_text' => 'g'],
        ], $payload['item']);
    }

    /**
     * Absence is the default `each`, not "unknown", so an item without a sale basis must not
     * emit the key at all -- an explicit null would assert something different.
     */
    #[Test]
    public function anItemWithoutASaleBasisOmitsTheKey(): void
    {
        $payload = (new LineItem('mug', 'Enamel Mug', 12.0, 2))->toArray();

        self::assertArrayNotHasKey('quantity_unit', $payload['item']);
        self::assertArrayNotHasKey('unit_price', $payload['item']);
    }

    /**
     * `measure` is what the product contains; `reference` is the denominator the price is shown
     * against. Both are needed for a shelf label to read "per 100 mL" against a 750 mL bottle
     * without the platform guessing either number.
     */
    #[Test]
    public function aUnitPriceCarriesBothItsContentAndItsDisplayDenominator(): void
    {
        $unitPrice = new UnitPrice(
            160,
            'eur',
            new Measure(new Unit('MLT', 'mL'), 750),
            new Measure(new Unit('MLT', 'mL'), 100),
        );

        $payload = $unitPrice->toArray();

        self::assertSame(160, $payload['amount']);
        self::assertSame('EUR', $payload['currency'], 'uppercased like everywhere else');
        self::assertSame(750, $payload['measure']['value']);
        self::assertSame(100, $payload['reference']['value']);
    }

    #[Test]
    public function scaleIsOmittedAtItsSchemaDefault(): void
    {
        self::assertArrayNotHasKey('scale', (new Unit('KGM', 'kg'))->toArray());
        self::assertSame(3, (new Unit('KGM', 'kg', 3))->toArray()['scale']);
    }

    #[Test]
    public function aPartialUnitPriceIsDroppedRatherThanPublishedHalfFormed(): void
    {
        self::assertNull(UnitPrice::fromArray(['amount' => 160, 'currency' => 'EUR']));
        self::assertNull(Measure::fromArray(['unit' => 'MLT', 'display_text' => 'mL']));
        self::assertNull(Unit::fromArray(['unit' => 'MLT']));
    }

    #[Test]
    public function itRoundTripsThroughTheWireShape(): void
    {
        $original = new UnitPrice(
            160,
            'EUR',
            new Measure(new Unit('MLT', 'mL'), 750),
            new Measure(new Unit('MLT', 'mL', 1), 100),
        );

        $reread = UnitPrice::fromArray($original->toArray());

        self::assertNotNull($reread);
        self::assertSame($original->toArray(), $reread->toArray());
        self::assertSame(1, $reread->reference->unit->scale);
    }
}
