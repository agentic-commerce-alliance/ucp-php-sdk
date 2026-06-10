<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Common;

final class Message
{
    public function __construct(
        public readonly string $type,
        public readonly string $content,
        public readonly ?string $severity = null,
        public readonly ?string $code = null,
        public readonly ?string $path = null,
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
