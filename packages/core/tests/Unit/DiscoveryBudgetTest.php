<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Model\Config\DiscoveryBudget;

final class DiscoveryBudgetTest extends TestCase
{
    #[Test]
    public function itAlignsTheWindowStartToTheWindowLength(): void
    {
        $budget = new DiscoveryBudget(windowSeconds: 60);

        self::assertSame(1_700_000_040, $budget->windowStart(1_700_000_059));
        self::assertSame(1_700_000_100, $budget->windowStart(1_700_000_100));
    }

    #[Test]
    public function itDoublesTheBackoffPerConsecutiveFailureUpToTheMaximum(): void
    {
        $budget = new DiscoveryBudget(backoffBaseSeconds: 30, backoffMaxSeconds: 100);

        self::assertSame(30, $budget->backoffSeconds(1));
        self::assertSame(60, $budget->backoffSeconds(2));
        self::assertSame(100, $budget->backoffSeconds(3), 'capped at the maximum');
        self::assertSame(100, $budget->backoffSeconds(40), 'a huge count does not overflow');
        self::assertSame(30, $budget->backoffSeconds(0), 'a count below one is the base');
    }
}
