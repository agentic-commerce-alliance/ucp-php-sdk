<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Common;

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
