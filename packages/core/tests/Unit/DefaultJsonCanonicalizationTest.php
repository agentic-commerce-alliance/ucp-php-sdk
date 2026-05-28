<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Internal\Security\DefaultJsonCanonicalization;

final class DefaultJsonCanonicalizationTest extends TestCase
{
    #[Test]
    public function itSortsAssociativeKeysRecursivelyAndKeepsListsStable(): void
    {
        $service = new DefaultJsonCanonicalization();

        $result = $service->canonicalize([
            'z' => 1,
            'a' => [
                'b' => 2,
                'a' => 1,
                'list' => [
                    ['z' => 2, 'a' => 1],
                    ['b' => 2, 'a' => 1],
                ],
            ],
        ]);

        self::assertSame('{"a":{"a":1,"b":2,"list":[{"a":1,"z":2},{"a":1,"b":2}]},"z":1}', $result);
    }

    #[Test]
    public function itOrdersObjectKeysUsingUtf16CodeUnits(): void
    {
        $service = new DefaultJsonCanonicalization();

        $result = $service->canonicalize([
            "\u{E000}" => 1,
            "\u{10300}" => 2,
        ]);

        self::assertSame('{"𐌀":2,"":1}', $result);
    }

    #[Test]
    public function itUsesShortestJsonNumberRepresentations(): void
    {
        $service = new DefaultJsonCanonicalization();

        $result = $service->canonicalize([
            'big' => 1.0e30,
            'negativeZero' => -0.0,
            'small' => 0.002,
        ]);

        self::assertSame('{"big":1e+30,"negativeZero":0,"small":0.002}', $result);
    }
}
