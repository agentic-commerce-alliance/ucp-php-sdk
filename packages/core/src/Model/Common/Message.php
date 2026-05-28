<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Common;

final readonly class Message
{
    public function __construct(
        public string $type,
        public string $content,
        public ?string $severity = null,
        public ?string $code = null,
        public ?string $path = null,
    ) {
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return array_filter([
            'type' => $this->type,
            'content' => $this->content,
            'severity' => $this->severity,
            'code' => $this->code,
            'path' => $this->path,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
