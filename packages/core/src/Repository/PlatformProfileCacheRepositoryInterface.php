<?php

declare(strict_types=1);

namespace Ucp\Sdk\Repository;

use Ucp\Sdk\Model\Profile\PlatformProfile;

interface PlatformProfileCacheRepositoryInterface
{
    public function save(string $uri, PlatformProfile $profile): void;

    public function find(string $uri, bool $allowExpired = false): ?PlatformProfile;

    public function purgeExpired(int $olderThanUnixTimestamp): void;
}
