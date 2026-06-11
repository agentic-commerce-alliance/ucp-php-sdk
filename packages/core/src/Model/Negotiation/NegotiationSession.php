<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Negotiation;

final class NegotiationSession
{
    /**
     * @param list<string> $activeCapabilities
     * @param list<string> $paymentHandlerIds
     */
    public function __construct(
        public readonly string $id,
        public readonly string $platformProfileUri,
        public readonly string $protocolVersion,
        public readonly array $activeCapabilities,
        public readonly array $paymentHandlerIds = [],
        public readonly ?string $tenantIdentifier = null,
        public readonly ?string $lastUsedAt = null,
    ) {
    }
}
