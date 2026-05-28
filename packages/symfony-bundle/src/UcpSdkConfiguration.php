<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony;

use Ucp\Sdk\Enum\SignaturePolicy;
use Ucp\Sdk\Enum\Transport;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;

final readonly class UcpSdkConfiguration
{
    /**
     * @param list<string> $allowedProfileHosts
     * @param list<string> $allowedAgentDomains
     * @param array<string, string> $supportedVersions
     */
    public function __construct(
        public string $version,
        public ?string $baseUri,
        public array $allowedProfileHosts,
        public string $signaturePolicy,
        public array $allowedAgentDomains,
        public bool $idempotencyRequired,
        public int $idempotencyTtl,
        public int $maxRequestBodyBytes,
        public int $platformProfileCacheTtl,
        public int $negotiationSessionTtl,
        public int $signatureMaxLifetimeSeconds,
        public int $oauthAuthorizationCodeTtl,
        public array $supportedVersions,
        public bool $signingKeysAutoGenerate,
        public string $signingKeysDefaultKid,
        public string $signingKeysAlgorithm,
        public string $signingKeysRetireAfter,
        public string $signingKeysRetiredKeyRetention,
        public int $idempotencyMaxStoredResponseBytes,
        public int $webhookTimeout,
        public bool $ap2Enabled,
        public string $storageDsn,
    ) {
    }

    public function resolvedBaseUri(?string $fallback = null): string
    {
        return $this->baseUri ?? $fallback ?? '';
    }

    public function toRuntimeConfiguration(?string $fallbackBaseUri = null): RuntimeConfiguration
    {
        return new RuntimeConfiguration(
            $this->version,
            $this->resolvedBaseUri($fallbackBaseUri),
            SignaturePolicy::from($this->signaturePolicy),
            $this->idempotencyRequired,
            $this->allowedProfileHosts,
            $this->allowedAgentDomains,
            $this->supportedVersions,
            [Transport::Rest],
            [],
        );
    }
}
