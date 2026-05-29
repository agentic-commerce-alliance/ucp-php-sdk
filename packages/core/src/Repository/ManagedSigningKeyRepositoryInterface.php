<?php

declare(strict_types=1);

namespace Ucp\Sdk\Repository;

use Ucp\Sdk\Model\Security\ManagedSigningKey;

interface ManagedSigningKeyRepositoryInterface
{
    public function saveManaged(ManagedSigningKey $key): void;

    public function findManaged(string $kid): ?ManagedSigningKey;

    public function deleteManaged(string $kid): bool;

    /**
     * @return list<ManagedSigningKey>
     */
    public function allManaged(): array;

    /**
     * @return list<ManagedSigningKey>
     */
    public function active(): array;

    public function purgeRetired(string $olderThanIso8601): void;
}
