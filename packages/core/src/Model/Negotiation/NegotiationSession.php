<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Negotiation;

final readonly class NegotiationSession
{
    /**
     * @param list<string> $activeCapabilities
     * @param list<string> $paymentHandlerIds
     */
    public function __construct(
        public string $id,
        public string $platformProfileUri,
        public string $protocolVersion,
        public array $activeCapabilities,
        public array $paymentHandlerIds = [],
        public ?string $tenantIdentifier = null,
        public ?string $lastUsedAt = null,
    ) {
    }
}
