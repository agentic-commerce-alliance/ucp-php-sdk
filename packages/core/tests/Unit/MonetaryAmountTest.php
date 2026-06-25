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
}
