<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model;

final class IdempotencyRecord
{
    /**
     * @param array<string, mixed>|null $responseBody
     */
    public function __construct(
        public readonly string $key,
        public readonly string $fingerprint,
        public readonly string $status = 'pending',
        public readonly ?array $responseBody = null,
        public readonly ?int $statusCode = null,
        public readonly bool $replayable = true,
    ) {
    }
}
