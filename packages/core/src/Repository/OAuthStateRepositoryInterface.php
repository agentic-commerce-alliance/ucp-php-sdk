<?php

declare(strict_types=1);

namespace Ucp\Sdk\Repository;

use Ucp\Sdk\Model\OAuthState;

interface OAuthStateRepositoryInterface
{
    public function save(OAuthState $state): void;

    public function consume(string $code): ?OAuthState;

    public function purgeExpired(int $olderThanUnixTimestamp): void;
}
