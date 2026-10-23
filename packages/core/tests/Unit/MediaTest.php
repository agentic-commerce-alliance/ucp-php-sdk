<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Common\Media;

final class MediaTest extends TestCase
{
    #[Test]
    public function itOmitsUnsetOptionalFields(): void
    {
        $media = new Media('image', 'https://example.test/front.jpg');

        self::assertSame(['type' => 'image', 'url' => 'https://example.test/front.jpg'], $media->toArray());
    }

    #[Test]
    public function anEmptyTypeIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        new Media(' ', 'https://example.test/front.jpg');
    }

    #[Test]
    public function anEmptyUrlIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        new Media('image', '');
    }

    #[Test]
    public function aZeroWidthIsRejected(): void
    {
        // The pinned media schema sets a minimum of 1 on width and height.
        $this->expectException(ValidationException::class);

        new Media('image', 'https://example.test/front.jpg', width: 0);
    }

    #[Test]
    public function aZeroHeightIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        new Media('image', 'https://example.test/front.jpg', height: 0);
    }

    #[Test]
    public function aOnePixelImageIsAccepted(): void
    {
        $media = new Media('image', 'https://example.test/pixel.gif', width: 1, height: 1);

        self::assertSame(1, $media->toArray()['width'] ?? null);
        self::assertSame(1, $media->toArray()['height'] ?? null);
    }
}
