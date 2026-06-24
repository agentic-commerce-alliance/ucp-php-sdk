<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Negotiation;

use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\Profile\PaymentHandlerDescriptor;

final class NegotiatedCapabilities
{
    /**
     * @param array<string, list<CapabilityDescriptor>> $capabilities
     * @param list<string> $paymentHandlerIds
     * @param array<string, list<string>> $operationCapabilityMap
     * @param array<string, list<PaymentHandlerDescriptor>> $paymentHandlers
     */
    public function __construct(
        public readonly array $capabilities = [],
        public readonly array $paymentHandlerIds = [],
        public readonly array $operationCapabilityMap = [],
        public readonly array $paymentHandlers = [],
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
