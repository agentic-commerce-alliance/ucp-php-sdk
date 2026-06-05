<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Enum\SignaturePolicy;
use Ucp\Sdk\Enum\Transport;
use Ucp\Sdk\Symfony\UcpSdkConfiguration;

final class UcpSdkConfigurationTest extends TestCase
{
    #[Test]
    public function itResolvesConfiguredFallbackAndEmptyBaseUri(): void
    {
        self::assertSame('https://merchant.example', $this->configuration(baseUri: 'https://merchant.example')->resolvedBaseUri('https://fallback.example'));
        self::assertSame('https://fallback.example', $this->configuration(baseUri: null)->resolvedBaseUri('https://fallback.example'));
        self::assertSame('', $this->configuration(baseUri: null)->resolvedBaseUri());
    }

    #[Test]
    public function itChecksEnabledTransports(): void
    {
        $configuration = $this->configuration(transports: [Transport::Rest, Transport::A2a]);

        self::assertTrue($configuration->supportsTransport(Transport::Rest));
        self::assertTrue($configuration->supportsTransport(Transport::A2a));
        self::assertFalse($configuration->supportsTransport(Transport::Embedded));
    }

    #[Test]
    public function itAllowsOnlyConfiguredOrBaseUriOrigins(): void
    {
        $configuration = $this->configuration(
            baseUri: null,
            allowedAgentDomains: ['agent.example'],
        );

        self::assertTrue($configuration->allowsOrigin('https://agent.example'));
        self::assertTrue($configuration->allowsOrigin('https://merchant.example', 'https://merchant.example/base'));
        self::assertFalse($configuration->allowsOrigin('not-a-url'));
        self::assertFalse($configuration->allowsOrigin('https://evil.example', 'https://merchant.example'));
    }

    #[Test]
    public function itConvertsToRuntimeConfiguration(): void
    {
        $configuration = $this->configuration(
            baseUri: null,
            signaturePolicy: SignaturePolicy::Strict->value,
            allowedProfileHosts: ['profiles.example'],
            allowedAgentDomains: ['agent.example'],
            supportedVersions: ['2025-10-01' => 'https://merchant.example/.well-known/ucp/2025-10-01'],
            transports: [Transport::Rest, Transport::A2a],
            transportEndpoints: ['a2a' => 'https://merchant.example/ucp/a2a'],
        );

        $runtime = $configuration->toRuntimeConfiguration('https://merchant.example');

        self::assertSame('2026-04-08', $runtime->version);
        self::assertSame('https://merchant.example', $runtime->baseUri);
        self::assertSame(SignaturePolicy::Strict, $runtime->signaturePolicy);
        self::assertSame(['profiles.example'], $runtime->allowedProfileHosts);
        self::assertSame(['agent.example'], $runtime->allowedAgentDomains);
        self::assertSame(['2025-10-01' => 'https://merchant.example/.well-known/ucp/2025-10-01'], $runtime->supportedVersions);
        self::assertSame([Transport::Rest, Transport::A2a], $runtime->transports);
        self::assertSame(['a2a' => 'https://merchant.example/ucp/a2a'], $runtime->transportEndpoints);
    }

    /**
     * @param list<string> $allowedProfileHosts
     * @param list<string> $allowedAgentDomains
     * @param array<string, string> $supportedVersions
     * @param list<Transport> $transports
     * @param array<string, string> $transportEndpoints
     */
    private function configuration(
        ?string $baseUri = 'https://merchant.example',
        string $signaturePolicy = 'log',
        array $allowedProfileHosts = [],
        array $allowedAgentDomains = [],
        array $supportedVersions = [],
        array $transports = [Transport::Rest],
        array $transportEndpoints = [],
    ): UcpSdkConfiguration {
        return new UcpSdkConfiguration(
            '2026-04-08',
            $baseUri,
            $allowedProfileHosts,
            $signaturePolicy,
            $allowedAgentDomains,
            false,
            86400,
            262144,
            600,
            604800,
            300,
            600,
            $supportedVersions,
            false,
            'default',
            'ES256',
            'P30D',
            'P30D',
            262144,
            10,
            false,
            'sqlite:///%kernel.project_dir%/var/ucp_sdk.sqlite',
            $transports,
            $transportEndpoints,
        );
    }
}
