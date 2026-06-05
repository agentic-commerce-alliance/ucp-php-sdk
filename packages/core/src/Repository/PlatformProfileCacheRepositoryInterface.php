<?php

declare(strict_types=1);

namespace Ucp\Sdk\Repository;

use Ucp\Sdk\Model\Profile\PlatformProfile;

interface PlatformProfileCacheRepositoryInterface
{
    public function save(string $uri, PlatformProfile $profile): void;

    public function find(string $uri, bool $allowExpired = false): ?PlatformProfile;

    /**
     * @return array<string, PlatformProfile>
     */
    public function all(bool $allowExpired = false): array;

    public function delete(string $uri): bool;

    public function purgeExpired(int $olderThanUnixTimestamp): void;
}
