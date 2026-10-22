<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Model\Catalog\Product;
use Ucp\Sdk\Model\Common\Media;

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
            extra: ['description' => ['plain' => 'From extra', 'html' => '<p>From extra</p>']],
        );

        $payload = $product->toArray();

        self::assertSame(['plain' => 'From extra', 'html' => '<p>From extra</p>'], $payload['description']);
    }

    #[Test]
    public function itKeepsLegacyOutputWhenMediaIsOmitted(): void
    {
        $legacy = new Product('p-1', 'Runner Pro', 19.99, 'https://example.test/cover.jpg');
        $omitted = new Product('p-1', 'Runner Pro', 19.99, 'https://example.test/cover.jpg', media: null);

        $payload = $omitted->toArray();

        self::assertSame($legacy->toArray(), $payload);
        self::assertArrayNotHasKey('media', $payload);
        self::assertSame('https://example.test/cover.jpg', $payload['image_url'] ?? null);
    }

    #[Test]
    public function itSerializesOrderedTypedMediaAndDerivesImageUrlFromFirstImage(): void
    {
        $product = new Product('p-1', 'Runner Pro', 19.99, media: [
            new Media('image', 'https://example.test/front.jpg', 'Front view', 800, 600),
            new Media('video', 'https://example.test/demo.mp4'),
            new Media('model_3d', 'https://example.test/model.glb'),
        ]);

        $payload = $product->toArray();

        self::assertSame([
            ['type' => 'image', 'url' => 'https://example.test/front.jpg', 'alt_text' => 'Front view', 'width' => 800, 'height' => 600],
            ['type' => 'video', 'url' => 'https://example.test/demo.mp4'],
            ['type' => 'model_3d', 'url' => 'https://example.test/model.glb'],
        ], $payload['media'] ?? null);
        self::assertSame('https://example.test/front.jpg', $payload['image_url'] ?? null);
        self::assertArrayNotHasKey('media', $payload['variants'][0]);
    }

    #[Test]
    public function itPreservesAnExplicitImageUrlAndTheExtraOverride(): void
    {
        $product = new Product(
            'p-1',
            'Runner Pro',
            19.99,
            'https://example.test/legacy.jpg',
            extra: ['media' => []],
            media: [new Media('video', 'https://example.test/demo.mp4')],
        );

        $payload = $product->toArray();

        self::assertSame('https://example.test/legacy.jpg', $payload['image_url'] ?? null);
        self::assertSame([], $payload['media'] ?? null);
    }

    #[Test]
    public function itDoesNotDeriveImageUrlFromNonImageOrEmptyMedia(): void
    {
        $video = new Product('p-1', 'Runner Pro', 19.99, media: [new Media('video', 'https://example.test/demo.mp4')]);
        $empty = (new Product('p-1', 'Runner Pro', 19.99, media: []))->toArray();

        self::assertArrayNotHasKey('image_url', $video->toArray());
        self::assertSame([], $empty['media'] ?? null);
    }
}
