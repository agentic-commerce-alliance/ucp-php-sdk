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
     */
    public function __construct(
        public readonly string $version,
        public readonly string $baseUri,
        public readonly array $transports = [Transport::Rest],
        public readonly array $supportedVersions = [],
        public readonly array $transportEndpoints = [],
        public readonly ?string $tenantIdentifier = null,
    ) {
    }
}
