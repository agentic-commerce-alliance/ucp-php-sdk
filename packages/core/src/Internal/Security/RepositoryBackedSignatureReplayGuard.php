<?php

declare(strict_types=1);

namespace Ucp\Sdk\Internal\Security;

use Ucp\Sdk\Exception\SignatureException;
use Ucp\Sdk\Repository\SignatureNonceRepositoryInterface;
use Ucp\Sdk\Service\SignatureReplayGuardInterface;

/** @internal */
final class RepositoryBackedSignatureReplayGuard implements SignatureReplayGuardInterface
{
    public function __construct(
        private readonly SignatureNonceRepositoryInterface $repository,
    ) {
    }

    public function rememberOrThrow(string $scope, string $kid, string $signature, ?int $created = null): void
    {
        $hash = hash('sha256', $signature);
        if (! $this->repository->saveIfNew($scope, $kid, $hash, $created)) {
            throw new SignatureException('Request signature replay detected.');
        }
    }
}
