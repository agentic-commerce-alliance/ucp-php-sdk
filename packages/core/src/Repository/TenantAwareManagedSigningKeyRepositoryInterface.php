<?php

declare(strict_types=1);

namespace Ucp\Sdk\Repository;

use Ucp\Sdk\Model\Security\ManagedSigningKey;

interface TenantAwareManagedSigningKeyRepositoryInterface
{
    public function saveManagedForTenant(?string $tenantIdentifier, ManagedSigningKey $key): void;

    public function findManagedForTenant(?string $tenantIdentifier, string $kid): ?ManagedSigningKey;

    public function deleteManagedForTenant(?string $tenantIdentifier, string $kid): bool;

    /**
     * @return list<ManagedSigningKey>
     */
    public function allManagedForTenant(?string $tenantIdentifier): array;

    /**
     * @return list<ManagedSigningKey>
     */
    public function activeForTenant(?string $tenantIdentifier): array;
}
