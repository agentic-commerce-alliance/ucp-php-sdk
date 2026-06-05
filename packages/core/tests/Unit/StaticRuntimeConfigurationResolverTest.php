<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Enum\SignaturePolicy;
use Ucp\Sdk\Enum\Transport;
use Ucp\Sdk\Internal\Configuration\StaticRuntimeConfigurationResolver;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\Http\HttpRequest;

final class StaticRuntimeConfigurationResolverTest extends TestCase
{
    #[Test]
    public function itAlwaysReturnsTheConfiguredRuntimeConfiguration(): void
    {
        $configuration = new RuntimeConfiguration(
            '2026-04-08',
            'https://merchant.example',
            SignaturePolicy::Strict,
            true,
            ['merchant.example'],
            ['merchant.example'],
            ['2026-04-08' => 'https://merchant.example/.well-known/ucp'],
            [Transport::Rest],
            ['demo.capability'],
            'tenant-1',
        );

        $resolver = new StaticRuntimeConfigurationResolver($configuration);
        $resolved = $resolver->resolve(new HttpRequest('GET', 'https://merchant.example/.well-known/ucp'));

        self::assertSame($configuration, $resolved);
    }
}
