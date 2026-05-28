<?php

declare(strict_types=1);

namespace Ucp\Sdk\Service;

use Ucp\Sdk\Contract\CapabilityInterface;

interface CapabilityRegistryInterface
{
    /**
     * @return list<CapabilityInterface>
     */
    public function all(): array;

    public function find(string $name): ?CapabilityInterface;

    public function firstImplementing(string $interface): ?CapabilityInterface;
}
