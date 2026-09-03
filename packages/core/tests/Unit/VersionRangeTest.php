<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Profile\VersionRange;

final class VersionRangeTest extends TestCase
{
    #[Test]
    public function anOpenRangeHasNoUpperBound(): void
    {
        $range = new VersionRange('2026-04-08');

        self::assertFalse($range->contains('2026-04-07'));
        self::assertTrue($range->contains('2026-04-08'), 'the minimum is inclusive');
        self::assertTrue($range->contains('2099-01-01'));
    }

    #[Test]
    public function aClosedRangeIncludesBothBounds(): void
    {
        $range = new VersionRange('2026-04-08', '2026-08-25');

        self::assertTrue($range->contains('2026-04-08'));
        self::assertTrue($range->contains('2026-08-25'));
        self::assertFalse($range->contains('2026-08-26'));
    }

    #[Test]
    public function intersectingWithAnOpenRangeKeepsTheOtherBound(): void
    {
        $bounded = new VersionRange('2026-01-01', '2026-06-01');
        $open = new VersionRange('2026-03-01');

        self::assertSame(['min' => '2026-03-01', 'max' => '2026-06-01'], $bounded->intersect($open)?->toArray());
        self::assertSame(['min' => '2026-03-01', 'max' => '2026-06-01'], $open->intersect($bounded)?->toArray());
    }

    #[Test]
    public function twoOpenRangesIntersectToAnOpenRange(): void
    {
        $result = (new VersionRange('2026-01-01'))->intersect(new VersionRange('2026-06-01'));

        self::assertSame(['min' => '2026-06-01'], $result?->toArray());
    }

    #[Test]
    public function disjointRangesDoNotIntersect(): void
    {
        self::assertNull(
            (new VersionRange('2026-01-01', '2026-04-08'))->intersect(new VersionRange('2026-08-25')),
        );
    }

    #[Test]
    public function touchingRangesIntersectAtTheSingleSharedVersion(): void
    {
        // The bounds are inclusive, so ranges that meet at one version overlap there rather
        // than being disjoint -- the off-by-one this comparison invites.
        $result = (new VersionRange('2026-01-01', '2026-04-08'))->intersect(new VersionRange('2026-04-08'));

        self::assertSame(['min' => '2026-04-08', 'max' => '2026-04-08'], $result?->toArray());
    }

    #[Test]
    public function itPicksTheNewestVersionWithinTheRange(): void
    {
        $range = new VersionRange('2026-01-01', '2026-08-25');

        self::assertSame('2026-08-25', $range->highestWithin(['2026-04-08', '2026-08-25', '2026-01-01']));
        self::assertSame('2026-04-08', $range->highestWithin(['2026-04-08', '2026-12-01']), 'out-of-range versions are not candidates');
        self::assertNull($range->highestWithin(['2026-12-01']));
        self::assertNull($range->highestWithin([]));
    }

    #[Test]
    public function aBoundThatIsNotAUcpVersionIsRejected(): void
    {
        // A bound of "latest" or "1.0" compares as a string without complaining and silently
        // answers the wrong question.
        $this->expectException(ValidationException::class);

        new VersionRange('latest');
    }

    #[Test]
    public function aMaximumBeforeItsMinimumIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        new VersionRange('2026-08-25', '2026-04-08');
    }

    #[Test]
    public function itRoundTripsThroughItsArrayForm(): void
    {
        self::assertSame(['min' => '2026-04-08'], VersionRange::fromArray(['min' => '2026-04-08'], 'ctx')->toArray());
        self::assertSame(
            ['min' => '2026-04-08', 'max' => '2026-08-25'],
            VersionRange::fromArray(['min' => '2026-04-08', 'max' => '2026-08-25'], 'ctx')->toArray(),
        );
    }

    #[Test]
    public function aRangeWithoutAMinimumIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        VersionRange::fromArray(['max' => '2026-08-25'], 'dev.ucp.shopping.cart');
    }
}
