<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Config;

use Ucp\Sdk\Enum\SignaturePolicy;
use Ucp\Sdk\Enum\Transport;

final class RuntimeConfiguration
{
    /**
     * @param list<string> $allowedProfileHosts
     * @param list<string> $allowedAgentDomains
     * @param array<string, string> $supportedVersions
     * @param list<Transport> $transports
     * @param list<string> $enabledCapabilities
     * @param array<string, string> $transportEndpoints
     */
    public function __construct(
        public readonly string $version,
        public readonly string $baseUri,
        public readonly SignaturePolicy $signaturePolicy = SignaturePolicy::Log,
        public readonly bool $idempotencyRequired = false,
        public readonly array $allowedProfileHosts = [],
        public readonly array $allowedAgentDomains = [],
        public readonly array $supportedVersions = [],
        public readonly array $transports = [Transport::Rest],
        public readonly array $enabledCapabilities = [],
        public readonly ?string $tenantIdentifier = null,
        public readonly array $transportEndpoints = [],
        public readonly bool $profileFetchingDevelopmentMode = false,
    ) {
    }

    public function isCapabilityEnabled(string $capabilityName): bool
    {
        return $this->enabledCapabilities === [] || in_array($capabilityName, $this->enabledCapabilities, true);
    }
}
