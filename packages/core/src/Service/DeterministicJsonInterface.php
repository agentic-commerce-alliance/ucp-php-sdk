<?php

declare(strict_types=1);

namespace Ucp\Sdk\Service;

interface DeterministicJsonInterface
{
    /**
     * @param array<string, mixed> $payload
     */
    public function canonicalize(array $payload): string;
}
