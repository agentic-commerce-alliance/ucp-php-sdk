<?php

declare(strict_types=1);

namespace Ucp\Sdk\Service;

use Ucp\Sdk\Model\IdempotencyRecord;

interface IdempotencyServiceInterface
{
    public function claim(string $key, string $fingerprint): IdempotencyRecord;

    /**
     * @param array<string, mixed> $responseBody
     */
    public function complete(IdempotencyRecord $record, array $responseBody, int $statusCode, bool $replayable = true): void;

    public function abort(IdempotencyRecord $record): void;
}
