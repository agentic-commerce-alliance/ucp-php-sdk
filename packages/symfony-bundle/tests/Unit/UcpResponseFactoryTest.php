<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\Negotiation\NegotiatedCapabilities;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\Profile\PaymentHandlerDescriptor;
use Ucp\Sdk\Model\RequestContext;
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
    public function itAddsNegotiatedMetadataForTheCurrentOperation(): void
    {
        $factory = new UcpResponseFactory($this->configuration());
        $context = new RequestContext(
            'merchant.example',
            runtimeConfiguration: new RuntimeConfiguration('2026-04-08', 'https://merchant.example'),
            negotiation: new NegotiatedCapabilities(
                [
                    'dev.ucp.shopping.checkout' => [
                        new CapabilityDescriptor('dev.ucp.shopping.checkout', '2026-04-08', 'https://merchant.example/spec/checkout', 'https://merchant.example/schema/checkout'),
                    ],
                    'dev.ucp.shopping.order' => [
                        new CapabilityDescriptor('dev.ucp.shopping.order', '2026-04-08', 'https://merchant.example/spec/order', 'https://merchant.example/schema/order'),
                    ],
                ],
                ['wallet-1'],
                [
                    'checkout.create' => ['dev.ucp.shopping.checkout'],
                    'order.get' => ['dev.ucp.shopping.order'],
                ],
                [
                    'com.merchant.wallet' => [
                        new PaymentHandlerDescriptor('wallet-1', 'Wallet', '2026-04-08', 'https://merchant.example/spec/wallet', 'https://merchant.example/schema/wallet', [], ['merchant_id' => 'merchant-1']),
                    ],
                ],
            ),
        );

        $response = $factory->success(['id' => 'checkout-1'], context: $context, operation: 'checkout.create');
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(['dev.ucp.shopping.checkout'], array_keys($payload['ucp']['capabilities']));
        self::assertArrayNotHasKey('dev.ucp.shopping.order', $payload['ucp']['capabilities']);
        self::assertSame('2026-04-08', $payload['ucp']['capabilities']['dev.ucp.shopping.checkout'][0]['version']);
        self::assertSame('wallet-1', $payload['ucp']['payment_handlers']['com.merchant.wallet'][0]['id']);
        self::assertSame('merchant-1', $payload['ucp']['payment_handlers']['com.merchant.wallet'][0]['config']['merchant_id']);
    }

    #[Test]
    public function itLeavesNegotiatedSectionsOutWhenNoProfileWasNegotiated(): void
    {
        $factory = new UcpResponseFactory($this->configuration());

        $response = $factory->success(['items' => []], context: new RequestContext('merchant.example'));
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayNotHasKey('capabilities', $payload['ucp']);
        self::assertArrayNotHasKey('payment_handlers', $payload['ucp']);
    }

    #[Test]
    public function itAddsNegotiatedMetadataToErrorEnvelopes(): void
    {
        $factory = new UcpResponseFactory($this->configuration());
        $context = new RequestContext(
            'merchant.example',
            runtimeConfiguration: new RuntimeConfiguration('2026-04-08', 'https://merchant.example'),
            negotiation: new NegotiatedCapabilities([
                'dev.ucp.shopping.cart' => [
                    new CapabilityDescriptor('dev.ucp.shopping.cart', '2026-04-08', 'https://merchant.example/spec/cart', 'https://merchant.example/schema/cart'),
                ],
            ]),
        );

        $response = $factory->error('Broken', 422, context: $context);
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(['dev.ucp.shopping.cart'], array_keys($payload['ucp']['capabilities']));
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
