<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Profile;

use Ucp\Sdk\Enum\Transport;

final class ProfileBuildInput
{
    /**
     * @param list<Transport> $transports
     * @param array<string, string> $supportedVersions
     * @param array<string, string> $transportEndpoints
     * @param list<string> $enabledCapabilities
     * @param list<string> $allowedProfileHosts hosts whose agent profile this shop will fetch/trust (enforcement)
     * @param list<string> $allowedAgentDomains  agent domains this shop transacts with (enforcement)
     * @param bool          $advertiseSecurityPolicy publish the allowlists above under `ucp.security`;
     *                                               enforcement is independent of this flag
     */
    public function __construct(
        public readonly string $version,
        public readonly string $baseUri,
        public readonly array $transports = [Transport::Rest],
        public readonly array $supportedVersions = [],
        public readonly array $transportEndpoints = [],
        public readonly ?string $tenantIdentifier = null,
        public readonly array $enabledCapabilities = [],
        public readonly array $allowedProfileHosts = [],
        public readonly array $allowedAgentDomains = [],
        public readonly bool $advertiseSecurityPolicy = false,
    ) {
    }
}
