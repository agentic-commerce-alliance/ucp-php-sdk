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
    public function itOrdersObjectKeysWithEqualPrefixes(): void
    {
        $service = new DefaultJsonCanonicalization();

        $result = $service->canonicalize([
            'cart/line' => 2,
            'cart' => 1,
            'cart💳' => 3,
        ]);

        self::assertSame('{"cart":1,"cart/line":2,"cart💳":3}', $result);
    }

    #[Test]
    public function itDoesNotEscapeSlashesOrUnicodeCharacters(): void
    {
        $service = new DefaultJsonCanonicalization();

        $result = $service->canonicalize([
            'https://merchant.example/ü' => 'https://agent.example/支付',
        ]);

        self::assertSame('{"https://merchant.example/ü":"https://agent.example/支付"}', $result);
    }

    #[Test]
    public function itUsesShortestJsonNumberRepresentations(): void
    {
        $service = new DefaultJsonCanonicalization();

        $result = $service->canonicalize([
            'big' => 1.0e30,
            'negativeZero' => -0.0,
            'one' => 1.0,
            'positiveZero' => 0.0,
            'small' => 0.002,
        ]);

        self::assertSame('{"big":1e+30,"negativeZero":0,"one":1,"positiveZero":0,"small":0.002}', $result);
    }

    #[Test]
    public function itRejectsNonFiniteFloats(): void
    {
        $service = new DefaultJsonCanonicalization();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('NaN and Infinity are not allowed in canonical JSON.');

        $service->canonicalize(['total' => INF]);
    }
}
