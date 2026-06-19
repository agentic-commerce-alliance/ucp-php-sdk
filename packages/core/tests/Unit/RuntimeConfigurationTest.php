<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;

final class RuntimeConfigurationTest extends TestCase
{
    #[Test]
    public function itTreatsEveryCapabilityAsEnabledWhenNoAllowlistIsConfigured(): void
    {
        $configuration = new RuntimeConfiguration('2026-04-08', 'https://merchant.example');

        self::assertTrue($configuration->isCapabilityEnabled('dev.ucp.shopping.checkout'));
    }

    #[Test]
    public function itChecksConfiguredEnabledCapabilities(): void
    {
        $configuration = new RuntimeConfiguration(
            '2026-04-08',
            'https://merchant.example',
            enabledCapabilities: ['dev.ucp.shopping.checkout'],
        );

        self::assertTrue($configuration->isCapabilityEnabled('dev.ucp.shopping.checkout'));
        self::assertFalse($configuration->isCapabilityEnabled('dev.ucp.shopping.cart'));
    }
}
