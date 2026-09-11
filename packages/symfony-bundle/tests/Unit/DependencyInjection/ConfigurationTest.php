<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Ucp\Sdk\Symfony\DependencyInjection\Configuration;

final class ConfigurationTest extends TestCase
{
    /**
     * An unusable allow-list entry used to be dropped at request time, leaving an operator
     * with a list that refused every agent and nothing to read explaining why. Both gates
     * fail closed, so the symptom was "the feature does not work" -- exactly the report
     * nobody can act on. Refusing it at container build puts the message where the typo is.
     */
    public function testItRefusesAnAllowedAgentDomainItCannotActOn(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Invalid ucp_sdk.allowed_agent_domains entry');

        $this->process([
            'allowed_agent_domains' => ['agent.example', '*.wildcards-are-not-supported.example'],
        ]);
    }

    /**
     * Both notations are accepted, because both exist in the wild: the setting is named
     * for domains, and configurations were written as origins while that was the only form
     * the embedded transport honoured.
     */
    public function testItAcceptsBothADomainAndAFullOrigin(): void
    {
        $config = $this->process([
            'allowed_agent_domains' => ['agent.example', 'https://other-agent.example:8443'],
        ]);

        self::assertSame(['agent.example', 'https://other-agent.example:8443'], $config['allowed_agent_domains']);
    }

    public function testItRequiresExplicitMcpEndpointWhenMcpTransportIsEnabled(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('MCP transport requires an explicit "mcp" transport endpoint.');

        $this->process([
            'transports' => ['rest', 'mcp'],
        ]);
    }

    public function testItAcceptsMcpTransportWithExplicitEndpoint(): void
    {
        $config = $this->process([
            'transports' => ['rest', 'mcp'],
            'transport_endpoints' => [
                'mcp' => 'https://merchant.example/ucp/mcp',
            ],
        ]);

        self::assertSame(['rest', 'mcp'], $config['transports']);
        self::assertSame('https://merchant.example/ucp/mcp', $config['transport_endpoints']['mcp']);
    }

    public function testItDefaultsEnabledCapabilitiesToAnEmptyAllowlist(): void
    {
        $config = $this->process([]);

        self::assertSame([], $config['enabled_capabilities']);
    }

    public function testItAcceptsEnabledCapabilities(): void
    {
        $config = $this->process([
            'enabled_capabilities' => [
                'dev.ucp.shopping.cart',
                'dev.ucp.shopping.checkout',
            ],
        ]);

        self::assertSame([
            'dev.ucp.shopping.cart',
            'dev.ucp.shopping.checkout',
        ], $config['enabled_capabilities']);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function process(array $config): array
    {
        return (new Processor())->processConfiguration(new Configuration(), [$config]);
    }
}
