<?php

declare(strict_types=1);

namespace Ucp\Sdk\Repository;

use Ucp\Sdk\Model\Security\AgentKeyDirectory;

interface AgentKeyDirectoryCacheRepositoryInterface
{
    public function save(string $uri, AgentKeyDirectory $directory): void;

    public function find(string $uri, bool $allowExpired = false): ?AgentKeyDirectory;

    public function delete(string $uri): bool;

    public function purgeExpired(int $olderThanUnixTimestamp): void;
}
