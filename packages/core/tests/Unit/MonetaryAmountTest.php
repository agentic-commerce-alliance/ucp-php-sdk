<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Model\Common\MonetaryAmount;

final class MonetaryAmountTest extends TestCase
{
    #[Test]
    public function itConvertsMajorUnitsToMinorUnitsOnce(): void
    {
        $amount = MonetaryAmount::fromMajorUnits(12.345, 'USD');

        self::assertSame(1235, $amount->minorUnits);
        self::assertSame('USD', $amount->currency);
        self::assertSame(['amount' => 1235, 'currency' => 'USD'], $amount->toPriceArray());
    }

    #[Test]
    public function itUsesTheIsoMinorUnitExponentPerCurrency(): void
    {
        self::assertSame(1000, MonetaryAmount::fromMajorUnits(1000.0, 'JPY')->minorUnits);
        self::assertSame(500, MonetaryAmount::fromMajorUnits(500.0, 'KRW')->minorUnits);
        self::assertSame(12345, MonetaryAmount::fromMajorUnits(12.345, 'KWD')->minorUnits);
        self::assertSame(123450, MonetaryAmount::fromMajorUnits(12.345, 'CLF')->minorUnits);
        self::assertSame(1234, MonetaryAmount::fromMajorUnits(12.34, 'EUR')->minorUnits);
        self::assertSame(1000, MonetaryAmount::fromMajorUnits(1000.0, 'jpy')->minorUnits);
    }

    #[Test]
    public function itNormalizesTheStoredCurrencyToUppercase(): void
    {
        $amount = MonetaryAmount::fromMajorUnits(1000.0, 'jpy');

        self::assertSame(1000, $amount->minorUnits);
        self::assertSame('JPY', $amount->currency);
        self::assertSame(['amount' => 1000, 'currency' => 'JPY'], $amount->toPriceArray());
    }

    #[Test]
    public function itFallsBackToTwoDecimalsForUnknownCurrencies(): void
    {
        self::assertSame(1000, MonetaryAmount::fromMajorUnits(10.0, 'ZZZ')->minorUnits);
    }
}
