<?php

declare(strict_types=1);

namespace Ucp\Sdk\Adapter\Model;

final readonly class TokenizationResult
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public array $payload,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->payload;
    }
}
