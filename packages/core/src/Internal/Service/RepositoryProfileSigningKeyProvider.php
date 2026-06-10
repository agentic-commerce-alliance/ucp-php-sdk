<?php

declare(strict_types=1);

namespace Ucp\Sdk\Internal\Service;

use Ucp\Sdk\Contract\ProfileSigningKeyProviderInterface;
use Ucp\Sdk\Model\Profile\ProfileBuildInput;
use Ucp\Sdk\Repository\ManagedSigningKeyRepositoryInterface;
use Ucp\Sdk\Repository\TenantAwareManagedSigningKeyRepositoryInterface;
use Ucp\Sdk\Service\SigningKeyManagerInterface;

/** @internal */
final class RepositoryProfileSigningKeyProvider implements ProfileSigningKeyProviderInterface
{
    public function __construct(
        private readonly ManagedSigningKeyRepositoryInterface $repository,
        private readonly SigningKeyManagerInterface $signingKeyManager,
        private readonly bool $autoGenerate = false,
        private readonly string $defaultKid = 'default',
        private readonly string $defaultAlgorithm = 'ES256',
        private readonly ?string $retireAfter = null,
    ) {
    }

    public function provide(ProfileBuildInput $input): array
    {
        $keys = $this->repository instanceof TenantAwareManagedSigningKeyRepositoryInterface
            ? $this->repository->activeForTenant($input->tenantIdentifier)
            : $this->repository->active();

        if ($keys === [] && $this->autoGenerate) {
            $generated = $this->signingKeyManager->generate($this->defaultKid, $this->defaultAlgorithm);
            $generated = $this->withRetirement($generated);
            if ($this->repository instanceof TenantAwareManagedSigningKeyRepositoryInterface) {
                $this->repository->saveManagedForTenant($input->tenantIdentifier, $generated);
            } else {
                $this->repository->saveManaged($generated);
            }
            $keys = [$generated];
        }

        return array_map(
            fn ($key) => $this->signingKeyManager->toPublicKey($key),
            $keys,
        );
    }

    private function withRetirement(\Ucp\Sdk\Model\Security\ManagedSigningKey $key): \Ucp\Sdk\Model\Security\ManagedSigningKey
    {
        if ($this->retireAfter === null || $this->retireAfter === '') {
            return $key;
        }

        $retireAt = null;
        try {
            $retireAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->add(new \DateInterval($this->retireAfter))
                ->format(DATE_ATOM);
        } catch (\Throwable) {
            $retireAt = null;
        }

        return new \Ucp\Sdk\Model\Security\ManagedSigningKey(
            $key->kid,
            $key->publicKeyPem,
            $key->privateKeyPem,
            $key->algorithm,
            $key->keyType,
            $key->use,
            $key->status,
            $key->curve,
            $key->createdAt,
            $retireAt,
        );
    }
}
