<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Model\Catalog\Product;

final class ProductTest extends TestCase
{
    #[Test]
    public function itFallsBackToTheTitleWhenNoDescriptionIsProvided(): void
    {
        $product = new Product('gid://product/1', 'Runner Pro', 19.99, currency: 'EUR');

        $payload = $product->toArray();

        self::assertSame(['plain' => 'Runner Pro'], $payload['description']);
        self::assertSame(['plain' => 'Runner Pro'], $payload['variants'][0]['description']);
    }

    #[Test]
    public function itSerializesTheProvidedDescription(): void
    {
        $product = new Product(
            'gid://product/1',
            'Runner Pro',
            19.99,
            currency: 'EUR',
            description: 'A lightweight everyday running shoe.',
        );

        $payload = $product->toArray();

        self::assertSame(['plain' => 'A lightweight everyday running shoe.'], $payload['description']);
        self::assertSame(['plain' => 'A lightweight everyday running shoe.'], $payload['variants'][0]['description']);
        self::assertSame('Runner Pro', $payload['title']);
    }

    #[Test]
    public function extraStillOverridesTheDefaultDescription(): void
    {
        $product = new Product(
            'gid://product/1',
            'Runner Pro',
            19.99,
            currency: 'EUR',
            // @phpstan-ignore-next-line argument.type -- extra intentionally carries a richer shape than the property annotation.
            extra: ['description' => ['plain' => 'From extra', 'html' => '<p>From extra</p>']],
        );

        $payload = $product->toArray();

        self::assertSame(['plain' => 'From extra', 'html' => '<p>From extra</p>'], $payload['description']);
    }
}
