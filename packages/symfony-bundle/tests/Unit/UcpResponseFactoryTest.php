<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Symfony\Bridge\UcpResponseFactory;
use Ucp\Sdk\Symfony\UcpSdkConfiguration;

final class UcpResponseFactoryTest extends TestCase
{
    #[Test]
    public function itWrapsSuccessfulResponsesWithUcpMetadata(): void
    {
        $factory = new UcpResponseFactory($this->configuration());

        $response = $factory->success(['items' => []], 201, ['X-Test' => '1']);
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('1', $response->headers->get('X-Test'));
        self::assertSame('2026-04-08', $payload['ucp']['version']);
        self::assertSame('success', $payload['ucp']['status']);
    }

    #[Test]
    public function itRejectsReservedTopLevelUcpPayloads(): void
    {
        $factory = new UcpResponseFactory($this->configuration());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Top-level "ucp" is reserved for the protocol envelope.');

        $factory->success([
            'ucp' => ['version' => 'custom-version'],
        ]);
    }

    #[Test]
    public function itBuildsDefaultAndCustomErrorPayloads(): void
    {
        $factory = new UcpResponseFactory($this->configuration());

        $defaultResponse = $factory->error('Broken', 422);
        $defaultPayload = json_decode((string) $defaultResponse->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(422, $defaultResponse->getStatusCode());
        self::assertSame('error', $defaultPayload['ucp']['status']);
        self::assertSame('Broken', $defaultPayload['messages'][0]['content']);

        $customResponse = $factory->error('ignored', 409, [['type' => 'error', 'content' => 'Conflict']]);
        $customPayload = json_decode((string) $customResponse->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('Conflict', $customPayload['messages'][0]['content']);
    }

    private function configuration(): UcpSdkConfiguration
    {
        return new UcpSdkConfiguration(
            '2026-04-08',
            'https://merchant.example',
            [],
            'log',
            [],
            false,
            86400,
            262144,
            600,
            604800,
            300,
            600,
            [],
            false,
            'default',
            'ES256',
            'P30D',
            'P30D',
            262144,
            10,
            false,
            'sqlite:///%kernel.project_dir%/var/ucp_sdk.sqlite',
        );
    }
}
