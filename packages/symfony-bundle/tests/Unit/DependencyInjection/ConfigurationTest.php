<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Ucp\Sdk\Symfony\DependencyInjection\Configuration;

final class ConfigurationTest extends TestCase
{
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
