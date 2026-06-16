<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony;

use Ucp\Sdk\Enum\SignaturePolicy;
use Ucp\Sdk\Enum\Transport;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;

final class UcpSdkConfiguration
{
    /**
     * @param list<string> $allowedProfileHosts
     * @param list<string> $allowedAgentDomains
     * @param array<string, string> $supportedVersions
     * @param list<Transport> $transports
     * @param array<string, string> $transportEndpoints
     */
    public function __construct(
        public readonly string $version,
        public readonly ?string $baseUri,
        public readonly array $allowedProfileHosts,
        public readonly string $signaturePolicy,
        public readonly array $allowedAgentDomains,
        public readonly bool $idempotencyRequired,
        public readonly int $idempotencyTtl,
        public readonly int $maxRequestBodyBytes,
        public readonly int $platformProfileCacheTtl,
        public readonly int $negotiationSessionTtl,
        public readonly int $signatureMaxLifetimeSeconds,
        public readonly int $oauthAuthorizationCodeTtl,
        public readonly array $supportedVersions,
        public readonly bool $signingKeysAutoGenerate,
        public readonly string $signingKeysDefaultKid,
        public readonly string $signingKeysAlgorithm,
        public readonly string $signingKeysRetireAfter,
        public readonly string $signingKeysRetiredKeyRetention,
        public readonly int $idempotencyMaxStoredResponseBytes,
        public readonly int $webhookTimeout,
        public readonly bool $ap2Enabled,
        public readonly string $storageDsn,
        public readonly array $transports = [Transport::Rest],
        public readonly array $transportEndpoints = [],
        public readonly bool $profileFetchingDevelopmentMode = false,
    ) {
    }

    public function resolvedBaseUri(?string $fallback = null): string
    {
        return $this->baseUri ?? $fallback ?? '';
    }

    public function supportsTransport(Transport $transport): bool
    {
        return in_array($transport, $this->transports, true);
    }

    public function allowsOrigin(string $origin, ?string $fallbackBaseUri = null): bool
    {
        $host = parse_url($origin, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return false;
        }

        $allowedHosts = $this->allowedAgentDomains;
        $baseHost = parse_url($this->resolvedBaseUri($fallbackBaseUri), PHP_URL_HOST);
        if (is_string($baseHost) && $baseHost !== '') {
            $allowedHosts[] = $baseHost;
        }

        return in_array($host, array_unique($allowedHosts), true);
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
            $this->transports,
            [],
            transportEndpoints: $this->transportEndpoints,
            profileFetchingDevelopmentMode: $this->profileFetchingDevelopmentMode,
        );
    }
}
