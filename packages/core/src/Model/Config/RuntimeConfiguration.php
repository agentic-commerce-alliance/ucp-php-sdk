<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Config;

use Ucp\Sdk\Enum\SignaturePolicy;
use Ucp\Sdk\Enum\Transport;

final readonly class RuntimeConfiguration
{
    /**
     * @param list<string> $allowedProfileHosts
     * @param list<string> $allowedAgentDomains
     * @param array<string, string> $supportedVersions
     * @param list<Transport> $transports
     * @param list<string> $enabledCapabilities
     */
    public function __construct(
        public string $version,
        public string $baseUri,
        public SignaturePolicy $signaturePolicy = SignaturePolicy::Log,
        public bool $idempotencyRequired = false,
        public array $allowedProfileHosts = [],
        public array $allowedAgentDomains = [],
        public array $supportedVersions = [],
        public array $transports = [Transport::Rest],
        public array $enabledCapabilities = [],
        public ?string $tenantIdentifier = null,
    ) {
    }
}
