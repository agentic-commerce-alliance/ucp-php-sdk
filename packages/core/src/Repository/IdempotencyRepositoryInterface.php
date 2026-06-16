<?php

declare(strict_types=1);

namespace Ucp\Sdk\Repository;

use Ucp\Sdk\Model\IdempotencyRecord;

interface IdempotencyRepositoryInterface
{
    /**
     * Atomically creates a pending idempotency claim for this key.
     *
     * Returns true only when the caller owns a newly-created pending record.
     * Returns false when an active record already exists for the key; adapters
     * must leave that existing record unchanged so the service can inspect it.
     */
    public function claimPending(string $key, string $fingerprint): bool;

    public function find(string $key): ?IdempotencyRecord;

    public function save(IdempotencyRecord $record): void;

    public function delete(string $key): void;

    public function purgeExpired(int $olderThanUnixTimestamp): void;
}
