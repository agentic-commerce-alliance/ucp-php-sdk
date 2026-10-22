<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Common;

use Ucp\Sdk\Exception\ValidationException;

/** A product or variant media item in the UCP catalog. */
final class Media
{
    public function __construct(
        public readonly string $type,
        public readonly string $url,
        public readonly ?string $altText = null,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
    ) {
        if (trim($type) === '') {
            throw new ValidationException('Media type must be a non-empty string.');
        }

        if (trim($url) === '') {
            throw new ValidationException('Media URL must be a non-empty string.');
        }

        // The schema sets a minimum of 1 on both dimensions; 0 would publish an invalid payload.
        if ($width !== null && $width < 1) {
            throw new ValidationException(sprintf('Media width must be at least 1 pixel, %d given.', $width));
        }

        if ($height !== null && $height < 1) {
            throw new ValidationException(sprintf('Media height must be at least 1 pixel, %d given.', $height));
        }
    }

    /**
     * @return array{type: string, url: string, alt_text?: string, width?: int, height?: int}
     */
    public function toArray(): array
    {
        return array_filter([
            'type' => $this->type,
            'url' => $this->url,
            'alt_text' => $this->altText,
            'width' => $this->width,
            'height' => $this->height,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
