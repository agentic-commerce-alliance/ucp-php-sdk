<?php

declare(strict_types=1);

namespace Ucp\Sdk\Service;

interface SignatureReplayGuardInterface
{
    public function rememberOrThrow(string $scope, string $kid, string $signature, ?int $created = null): void;
}
