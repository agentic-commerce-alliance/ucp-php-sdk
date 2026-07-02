<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Ucp\Sdk\Model\Security\ManagedSigningKey;
use Ucp\Sdk\Repository\ManagedSigningKeyRepositoryInterface;
use Ucp\Sdk\Repository\TenantAwareManagedSigningKeyRepositoryInterface;

/**
 * Shared tenant handling for the signing-key commands.
 *
 * The commands accept an optional `--tenant` identifier and, when the injected
 * repository is tenant-aware, route reads/writes to that tenant's keys.
 * Integrators can override {@see resolveTenantIdentifier()} to source the tenant
 * from a domain-specific option instead (e.g. a Shopware sales-channel).
 */
trait InteractsWithSigningKeyTenant
{
    protected function configureTenantOption(): void
    {
        $this->addOption(
            'tenant',
            null,
            InputOption::VALUE_REQUIRED,
            'Tenant identifier the key belongs to (omit for the global/default scope).',
        );
    }

    /**
     * Resolve the tenant identifier for this invocation. Returns null for the
     * global/default scope.
     *
     * Override to map a domain-specific option (e.g. a Shopware sales channel)
     * to a tenant. The command's output is provided so an override can prompt
     * interactively or print guidance; throw to abort the command (the caller
     * lets the exception surface as a non-zero exit).
     */
    protected function resolveTenantIdentifier(InputInterface $input, OutputInterface $output): ?string
    {
        unset($output);

        $value = $input->hasOption('tenant') ? $input->getOption('tenant') : null;

        return \is_string($value) && '' !== $value ? $value : null;
    }

    protected function saveManagedKeyForTenant(
        ManagedSigningKeyRepositoryInterface $repository,
        ?string $tenantIdentifier,
        ManagedSigningKey $key,
    ): void {
        if (null !== $tenantIdentifier && $repository instanceof TenantAwareManagedSigningKeyRepositoryInterface) {
            $repository->saveManagedForTenant($tenantIdentifier, $key);

            return;
        }

        $repository->saveManaged($key);
    }

    protected function findManagedKeyForTenant(
        ManagedSigningKeyRepositoryInterface $repository,
        ?string $tenantIdentifier,
        string $kid,
    ): ?ManagedSigningKey {
        if (null !== $tenantIdentifier && $repository instanceof TenantAwareManagedSigningKeyRepositoryInterface) {
            return $repository->findManagedForTenant($tenantIdentifier, $kid);
        }

        return $repository->findManaged($kid);
    }

    protected function deleteManagedKeyForTenant(
        ManagedSigningKeyRepositoryInterface $repository,
        ?string $tenantIdentifier,
        string $kid,
    ): bool {
        if (null !== $tenantIdentifier && $repository instanceof TenantAwareManagedSigningKeyRepositoryInterface) {
            return $repository->deleteManagedForTenant($tenantIdentifier, $kid);
        }

        return $repository->deleteManaged($kid);
    }

    /**
     * @return list<ManagedSigningKey>
     */
    protected function allManagedKeysForTenant(ManagedSigningKeyRepositoryInterface $repository, ?string $tenantIdentifier): array
    {
        if (null !== $tenantIdentifier && $repository instanceof TenantAwareManagedSigningKeyRepositoryInterface) {
            return $repository->allManagedForTenant($tenantIdentifier);
        }

        return $repository->allManaged();
    }

    /**
     * @return list<ManagedSigningKey>
     */
    protected function activeKeysForTenant(ManagedSigningKeyRepositoryInterface $repository, ?string $tenantIdentifier): array
    {
        if (null !== $tenantIdentifier && $repository instanceof TenantAwareManagedSigningKeyRepositoryInterface) {
            return $repository->activeForTenant($tenantIdentifier);
        }

        return $repository->active();
    }
}
