<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Ucp\Sdk\Symfony\Command\DevRequestCommand;
use Ucp\Sdk\Symfony\UcpSdkConfiguration;

final class DevRequestCommandTest extends TestCase
{
    #[Test]
    public function itPrintsACurlThatUsesTheDeploymentsOwnProfileAsTheAgent(): void
    {
        $tester = new CommandTester(new DevRequestCommand($this->configuration(developmentMode: true)));

        $status = $tester->execute(['operation' => 'cart.create', '--id' => 'SW10001']);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString("curl -sS -X POST 'https://shop.example/ucp/v1/carts'", $display);
        self::assertStringContainsString('profile="https://shop.example/.well-known/ucp"', $display);
        self::assertStringContainsString("-H 'Idempotency-Key: dev-", $display);
        self::assertStringContainsString('{"line_items":[{"item":{"id":"SW10001"},"quantity":1}]}', $display);
        self::assertStringNotContainsString('development_mode is off', $display);
    }

    #[Test]
    public function itWarnsWhenDevelopmentModeIsOffBecauseTheRequestWillBeRefused(): void
    {
        $tester = new CommandTester(new DevRequestCommand($this->configuration(developmentMode: false)));

        $tester->execute(['operation' => 'catalog.search']);

        self::assertStringContainsString('profile_fetching_development_mode is off', $tester->getDisplay());
        self::assertStringContainsString("--data '{\"query\":\"tent\"}'", $tester->getDisplay());
    }

    #[Test]
    public function itListsTheOperationsWhenNoneIsNamed(): void
    {
        $tester = new CommandTester(new DevRequestCommand($this->configuration(developmentMode: true)));

        $status = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('catalog.search', $tester->getDisplay());
        self::assertStringContainsString('/ucp/v1/checkout-sessions/{id}/cancel', $tester->getDisplay());
    }

    #[Test]
    public function itRefusesAnUnknownOperationAndAMissingBaseUri(): void
    {
        $tester = new CommandTester(new DevRequestCommand($this->configuration(developmentMode: true)));
        self::assertSame(Command::INVALID, $tester->execute(['operation' => 'checkout.complete']));
        self::assertStringContainsString('Unknown operation', $tester->getDisplay());

        $tester = new CommandTester(new DevRequestCommand($this->configuration(developmentMode: true, baseUri: null)));
        self::assertSame(Command::INVALID, $tester->execute(['operation' => 'catalog.search']));
        self::assertStringContainsString('No base URI', $tester->getDisplay());
    }

    private function configuration(bool $developmentMode, ?string $baseUri = 'https://shop.example'): UcpSdkConfiguration
    {
        return new UcpSdkConfiguration(
            '2026-04-08',
            $baseUri,
            [],
            'log',
            [],
            true,
            86400,
            262144,
            600,
            604800,
            300,
            600,
            [],
            true,
            'default',
            'ES256',
            'P30D',
            'P30D',
            1048576,
            5,
            false,
            'sqlite:///:memory:',
            profileFetchingDevelopmentMode: $developmentMode,
        );
    }
}
