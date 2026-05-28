<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Negotiation;

use Ucp\Sdk\Model\Profile\CapabilityDescriptor;

final readonly class NegotiatedCapabilities
{
    /**
     * @param array<string, list<CapabilityDescriptor>> $capabilities
     * @param list<string> $paymentHandlerIds
     * @param array<string, list<string>> $operationCapabilityMap
     */
    public function __construct(
        public array $capabilities = [],
        public array $paymentHandlerIds = [],
        public array $operationCapabilityMap = [],
    ) {
    }

    /**
     * @return list<string>
     */
    public function capabilityNames(): array
    {
        return array_keys($this->capabilities);
    }

    /**
     * @return list<string>
     */
    public function capabilitiesForOperation(string $operation): array
    {
        return $this->operationCapabilityMap[$operation] ?? [];
    }
}
