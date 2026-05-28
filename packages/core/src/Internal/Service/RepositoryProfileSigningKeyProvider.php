<?php

declare(strict_types=1);

namespace Ucp\Sdk\Internal\Service;

use Ucp\Sdk\Contract\ProfileSigningKeyProviderInterface;
use Ucp\Sdk\Model\Profile\ProfileBuildInput;
use Ucp\Sdk\Repository\ManagedSigningKeyRepositoryInterface;
use Ucp\Sdk\Service\SigningKeyManagerInterface;

final readonly class RepositoryProfileSigningKeyProvider implements ProfileSigningKeyProviderInterface
{
    public function __construct(
        private ManagedSigningKeyRepositoryInterface $repository,
        private SigningKeyManagerInterface $signingKeyManager,
        private bool $autoGenerate = false,
        private string $defaultKid = 'default',
        private string $defaultAlgorithm = 'ES256',
        private ?string $retireAfter = null,
    ) {
    }

    public function provide(ProfileBuildInput $input): array
    {
        $keys = $this->repository->active();
        if ($keys === [] && $this->autoGenerate) {
            $generated = $this->signingKeyManager->generate($this->defaultKid, $this->defaultAlgorithm);
            $generated = $this->withRetirement($generated);
            $this->repository->saveManaged($generated);
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
