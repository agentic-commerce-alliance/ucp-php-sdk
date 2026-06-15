<?php

declare(strict_types=1);

namespace Ucp\Sdk\Repository;

use Ucp\Sdk\Model\IdempotencyRecord;

interface IdempotencyRepositoryInterface
{
    public function claimPending(string $key, string $fingerprint): bool;

    public function find(string $key): ?IdempotencyRecord;

    public function save(IdempotencyRecord $record): void;

    public function delete(string $key): void;

    public function purgeExpired(int $olderThanUnixTimestamp): void;
}
