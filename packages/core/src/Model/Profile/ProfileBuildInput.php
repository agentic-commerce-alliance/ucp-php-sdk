<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Profile;

use Ucp\Sdk\Enum\Transport;

final readonly class ProfileBuildInput
{
    /**
     * @param list<Transport> $transports
     * @param array<string, string> $supportedVersions
     * @param array<string, string> $transportEndpoints
     */
    public function __construct(
        public string $version,
        public string $baseUri,
        public array $transports = [Transport::Rest],
        public array $supportedVersions = [],
        public array $transportEndpoints = [],
        public ?string $tenantIdentifier = null,
    ) {
    }
}
