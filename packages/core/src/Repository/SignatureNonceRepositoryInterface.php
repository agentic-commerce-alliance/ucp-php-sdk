<?php

declare(strict_types=1);

namespace Ucp\Sdk\Repository;

interface SignatureNonceRepositoryInterface
{
    public function has(string $scope, string $kid, string $signatureHash): bool;

    public function save(string $scope, string $kid, string $signatureHash, ?int $createdAt = null): void;

    public function saveIfNew(string $scope, string $kid, string $signatureHash, ?int $createdAt = null): bool;

    public function purgeExpired(int $olderThanUnixTimestamp): void;
}
