<?php

declare(strict_types=1);

namespace Ucp\Sdk\Internal\Registry;

use Ucp\Sdk\Contract\CapabilityInterface;
use Ucp\Sdk\Service\CapabilityRegistryInterface;

/** @internal */
final class CapabilityRegistry implements CapabilityRegistryInterface
{
    /**
     * @param iterable<CapabilityInterface> $capabilities
     */
    public function __construct(
        iterable $capabilities,
    ) {
        $this->capabilities = array_values(iterator_to_array($capabilities));
        $this->capabilitiesByName = [];

        foreach ($this->capabilities as $capability) {
            $name = $capability->describe()->name;
            if (isset($this->capabilitiesByName[$name])) {
                throw new \InvalidArgumentException(sprintf('Capability "%s" is registered more than once.', $name));
            }

            $this->capabilitiesByName[$name] = $capability;
        }
    }

    /** @var list<CapabilityInterface> */
    private array $capabilities;

    /** @var array<string, CapabilityInterface> */
    private array $capabilitiesByName;

    public function all(): array
    {
        return $this->capabilities;
    }

    public function find(string $name): ?CapabilityInterface
    {
        return $this->capabilitiesByName[$name] ?? null;
    }

    public function firstImplementing(string $interface): ?CapabilityInterface
    {
        foreach ($this->capabilities as $capability) {
            if ($capability instanceof $interface) {
                return $capability;
            }
        }

        return null;
    }
}
