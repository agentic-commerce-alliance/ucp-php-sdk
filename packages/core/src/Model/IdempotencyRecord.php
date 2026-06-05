<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model;

final readonly class IdempotencyRecord
{
    /**
     * @param array<string, mixed>|null $responseBody
     */
    public function __construct(
        public string $key,
        public string $fingerprint,
        public string $status = 'pending',
        public ?array $responseBody = null,
        public ?int $statusCode = null,
        public bool $replayable = true,
    ) {
    }
}
