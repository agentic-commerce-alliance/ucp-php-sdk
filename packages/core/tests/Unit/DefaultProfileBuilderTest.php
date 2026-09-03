<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Contract\CapabilityInterface;
use Ucp\Sdk\Contract\PaymentHandlerInterface;
use Ucp\Sdk\Enum\Transport;
use Ucp\Sdk\Exception\ConfigurationException;
use Ucp\Sdk\Internal\Registry\CapabilityRegistry;
use Ucp\Sdk\Internal\Registry\PaymentHandlerRegistry;
use Ucp\Sdk\Internal\Service\DefaultProfileBuilder;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\Profile\PaymentHandlerDescriptor;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Model\Profile\ProfileBuildInput;
use Ucp\Sdk\Service\EventDispatcherInterface;

final class DefaultProfileBuilderTest extends TestCase
{
    public function testItBuildsDiscoveryProfiles(): void
    {
        $capability = $this->createMock(CapabilityInterface::class);
        $capability
            ->method('describe')
            ->willReturn(new CapabilityDescriptor('dev.ucp.shopping.checkout', '2026-04-08', 'https://ucp.dev/specification/checkout/', 'https://ucp.dev/schemas/shopping/checkout.json'));
        $handler = $this->createMock(PaymentHandlerInterface::class);
        $handler
            ->method('id')
            ->willReturn('demo');
        $handler
            ->method('describe')
            ->willReturn(new PaymentHandlerDescriptor('demo', 'com.demo.tokenizer', '2026-04-08', 'https://ucp.dev/specification/payment-handler-guide/', 'https://ucp.dev/schemas/payments/delegate-payment.json', ['schema']));

        $builder = new DefaultProfileBuilder(
            new CapabilityRegistry([$capability]),
            new PaymentHandlerRegistry([$handler]),
            [],
            [],
            new NullEventDispatcher(),
        );

        $profile = $builder->build(new ProfileBuildInput('2026-04-08', 'https://shop.example'));

        self::assertSame('2026-04-08', $profile->version);
        self::assertArrayHasKey('dev.ucp.shopping.checkout', $profile->capabilities);
        self::assertArrayHasKey('com.demo.tokenizer', $profile->paymentHandlers);
    }

    public function testItOnlyAdvertisesEnabledCapabilitiesWhenAnAllowlistIsConfigured(): void
    {
        $cartCapability = $this->createMock(CapabilityInterface::class);
        $cartCapability
            ->method('describe')
            ->willReturn(new CapabilityDescriptor('dev.ucp.shopping.cart', '2026-04-08', 'https://ucp.dev/specification/cart/', 'https://ucp.dev/schemas/shopping/cart.json'));
        $checkoutCapability = $this->createMock(CapabilityInterface::class);
        $checkoutCapability
            ->method('describe')
            ->willReturn(new CapabilityDescriptor('dev.ucp.shopping.checkout', '2026-04-08', 'https://ucp.dev/specification/checkout/', 'https://ucp.dev/schemas/shopping/checkout.json'));

        $builder = new DefaultProfileBuilder(
            new CapabilityRegistry([$cartCapability, $checkoutCapability]),
            new PaymentHandlerRegistry([]),
            [],
            [],
            new NullEventDispatcher(),
        );

        $profile = $builder->build(new ProfileBuildInput(
            '2026-04-08',
            'https://shop.example',
            enabledCapabilities: ['dev.ucp.shopping.checkout'],
        ));

        self::assertSame(['dev.ucp.shopping.checkout'], array_keys($profile->capabilities));
    }

    public function testItBuildsAllConfiguredTransportEndpoints(): void
    {
        $builder = new DefaultProfileBuilder(
            new CapabilityRegistry([]),
            new PaymentHandlerRegistry([]),
            [],
            [],
            new NullEventDispatcher(),
        );

        $profile = $builder->build(new ProfileBuildInput(
            '2026-04-08',
            'https://shop.example',
            [Transport::Rest, Transport::Mcp, Transport::A2a, Transport::Embedded],
            transportEndpoints: [
                Transport::Mcp->value => 'https://shop.example/ucp/mcp',
            ],
        ));

        $endpoints = $profile->services['dev.ucp.shopping'];

        self::assertSame('https://shop.example/ucp/v1', $endpoints[0]->endpoint);
        self::assertSame('https://shop.example/ucp/mcp', $endpoints[1]->endpoint);
        self::assertSame('https://shop.example/ucp/a2a', $endpoints[2]->endpoint);
        self::assertSame('https://shop.example/ucp/embedded', $endpoints[3]->endpoint);
        self::assertSame('https://ucp.dev/2026-04-08/services/shopping/rest.openapi.json', $endpoints[0]->schemaUrl);
        self::assertSame('https://ucp.dev/2026-04-08/services/shopping/mcp.openrpc.json', $endpoints[1]->schemaUrl);
        self::assertNull($endpoints[2]->schemaUrl);
        self::assertArrayNotHasKey('schema', $endpoints[2]->toArray());
        self::assertSame('https://ucp.dev/2026-04-08/services/shopping/embedded.openrpc.json', $endpoints[3]->schemaUrl);
    }

    public function testItRequiresExplicitMcpEndpoint(): void
    {
        $builder = new DefaultProfileBuilder(
            new CapabilityRegistry([]),
            new PaymentHandlerRegistry([]),
            [],
            [],
            new NullEventDispatcher(),
        );

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('MCP transport requires an explicit "mcp" transport endpoint.');

        $builder->build(new ProfileBuildInput(
            '2026-04-08',
            'https://shop.example',
            [Transport::Rest, Transport::Mcp],
        ));
    }

    public function testItRejectsEmptyMcpEndpoint(): void
    {
        $builder = new DefaultProfileBuilder(
            new CapabilityRegistry([]),
            new PaymentHandlerRegistry([]),
            [],
            [],
            new NullEventDispatcher(),
        );

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('MCP transport requires an explicit "mcp" transport endpoint.');

        $builder->build(new ProfileBuildInput(
            '2026-04-08',
            'https://shop.example',
            [Transport::Mcp],
            transportEndpoints: [
                Transport::Mcp->value => '',
            ],
        ));
    }
    /**
     * Two handlers can legitimately share a name and differ by id -- a tokenizer offering PAN
     * and network-token variants, say. This assigned rather than appended, so the second
     * silently replaced the first and the profile advertised whichever the registry yielded
     * last, while DefaultCapabilityNegotiator intersects on id and would have kept both.
     */
    public function testItPublishesEveryHandlerSharingAName(): void
    {
        $pan = $this->createMock(PaymentHandlerInterface::class);
        $pan->method('id')->willReturn('acme-pan');
        $pan->method('describe')->willReturn(new PaymentHandlerDescriptor(
            'acme-pan',
            'com.acme.tokenizer',
            '2026-04-08',
            'https://ucp.dev/specification/payment-handler-guide/',
            'https://acme.example/schemas/pan.json',
            ['https://acme.example/schemas/pan-instrument.json'],
        ));

        $networkToken = $this->createMock(PaymentHandlerInterface::class);
        $networkToken->method('id')->willReturn('acme-network-token');
        $networkToken->method('describe')->willReturn(new PaymentHandlerDescriptor(
            'acme-network-token',
            'com.acme.tokenizer',
            '2026-04-08',
            'https://ucp.dev/specification/payment-handler-guide/',
            'https://acme.example/schemas/network-token.json',
            ['https://acme.example/schemas/network-token-instrument.json'],
        ));

        $builder = new DefaultProfileBuilder(
            new CapabilityRegistry([]),
            new PaymentHandlerRegistry([$pan, $networkToken]),
            [],
            [],
            new NullEventDispatcher(),
        );

        $profile = $builder->build(new ProfileBuildInput('2026-04-08', 'https://shop.example'));

        self::assertArrayHasKey('com.acme.tokenizer', $profile->paymentHandlers);
        self::assertCount(2, $profile->paymentHandlers['com.acme.tokenizer']);
        self::assertSame(
            ['acme-pan', 'acme-network-token'],
            array_map(
                static fn (PaymentHandlerDescriptor $descriptor): string => $descriptor->id,
                $profile->paymentHandlers['com.acme.tokenizer'],
            ),
        );
    }

    /**
     * What a peer reads back out of the published profile has to be what went in, or the
     * handler set the SDK matches against is not the one it advertised.
     */
    public function testAPublishedProfileRoundTripsItsHandlerSet(): void
    {
        $first = $this->createMock(PaymentHandlerInterface::class);
        $first->method('id')->willReturn('acme-pan');
        $first->method('describe')->willReturn(new PaymentHandlerDescriptor(
            'acme-pan',
            'com.acme.tokenizer',
            '2026-04-08',
            'https://ucp.dev/specification/payment-handler-guide/',
            'https://acme.example/schemas/pan.json',
            ['https://acme.example/schemas/pan-instrument.json'],
        ));
        $second = $this->createMock(PaymentHandlerInterface::class);
        $second->method('id')->willReturn('acme-network-token');
        $second->method('describe')->willReturn(new PaymentHandlerDescriptor(
            'acme-network-token',
            'com.acme.tokenizer',
            '2026-04-08',
            'https://ucp.dev/specification/payment-handler-guide/',
            'https://acme.example/schemas/network-token.json',
            ['https://acme.example/schemas/network-token-instrument.json'],
        ));

        $builder = new DefaultProfileBuilder(
            new CapabilityRegistry([]),
            new PaymentHandlerRegistry([$first, $second]),
            [],
            [],
            new NullEventDispatcher(),
        );

        $published = $builder->build(new ProfileBuildInput('2026-04-08', 'https://shop.example'));

        // Through JSON, because that is the only way fromArray() is ever reached: it parses a
        // profile fetched over HTTP. Handing it toArray() directly does not round-trip -- an
        // empty registry section is emitted as a stdClass so it serialises as `{}` rather than
        // `[]`, and the parser wants arrays. Harmless on the wire, worth knowing when testing.
        $encoded = json_decode(json_encode($published->toArray(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($encoded);
        $reread = PlatformProfile::fromArray($encoded);

        self::assertSame(
            array_keys($published->paymentHandlers),
            array_keys($reread->paymentHandlers),
        );
        self::assertSame(
            ['acme-pan', 'acme-network-token'],
            array_map(
                static fn (PaymentHandlerDescriptor $descriptor): string => $descriptor->id,
                $reread->paymentHandlers['com.acme.tokenizer'],
            ),
        );
    }
}

final class NullEventDispatcher implements EventDispatcherInterface
{
    public function dispatch(object $event): object
    {
        return $event;
    }

}
