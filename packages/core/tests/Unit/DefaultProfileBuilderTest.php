<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Contract\CapabilityInterface;
use Ucp\Sdk\Contract\PaymentHandlerInterface;
use Ucp\Sdk\Contract\ProfileContributorInterface;
use Ucp\Sdk\Enum\Transport;
use Ucp\Sdk\Event\ProfileBuiltEvent;
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
     * Both extension points can rewrite the published profile, and they are not
     * equivalent: a contributor returns a profile and the builder takes the return value,
     * while a listener is handed a mutable event and has to call replaceProfile() for its
     * work to survive. Nothing exercised the second one, so the builder could have
     * discarded the event and every existing assertion would still have passed.
     *
     * The listener runs last, after every contributor, which is the part worth pinning
     * rather than merely observing -- a host that needs the final word on what it
     * publishes cannot get it from a contributor, because another contributor registered
     * later would overwrite it.
     */
    public function testAListenerHasTheFinalSayOverEveryContributor(): void
    {
        $builder = new DefaultProfileBuilder(
            new CapabilityRegistry([]),
            new PaymentHandlerRegistry([]),
            [new VersionRewritingContributor('rewritten-by-contributor')],
            [],
            new ProfileReplacingEventDispatcher('rewritten-by-listener'),
        );

        $profile = $builder->build(new ProfileBuildInput('2026-04-08', 'https://shop.example'));

        self::assertSame('rewritten-by-listener', $profile->version);
    }

    /**
     * The input is on the event because a listener deciding what to publish needs to know
     * what was asked for -- which base URI, which enabled capabilities. It was never read.
     * The contributor here is what proves the listener sees the contributed profile rather
     * than the one the builder assembled.
     */
    public function testTheProfileBuiltEventCarriesTheBuildInputAndTheContributedProfile(): void
    {
        $dispatcher = new ProfileReplacingEventDispatcher(null);
        $builder = new DefaultProfileBuilder(
            new CapabilityRegistry([]),
            new PaymentHandlerRegistry([]),
            [new VersionRewritingContributor('rewritten-by-contributor')],
            [],
            $dispatcher,
        );
        $input = new ProfileBuildInput('2026-04-08', 'https://shop.example');

        $profile = $builder->build($input);

        self::assertSame($input, $dispatcher->seenInput);
        self::assertSame('rewritten-by-contributor', $dispatcher->seenProfile?->version);
        self::assertSame('rewritten-by-contributor', $profile->version, 'A listener that replaces nothing must not undo the contributor.');
    }
}

final class NullEventDispatcher implements EventDispatcherInterface
{
    public function dispatch(object $event): object
    {
        return $event;
    }
}

final class VersionRewritingContributor implements ProfileContributorInterface
{
    public function __construct(private readonly string $version)
    {
    }

    public function contribute(PlatformProfile $profile, ProfileBuildInput $input): PlatformProfile
    {
        return new PlatformProfile(
            $this->version,
            $profile->services,
            $profile->capabilities,
            $profile->paymentHandlers,
            $profile->signingKeys,
            $profile->supportedVersions,
        );
    }
}

final class ProfileReplacingEventDispatcher implements EventDispatcherInterface
{
    public ?ProfileBuildInput $seenInput = null;

    public ?PlatformProfile $seenProfile = null;

    public function __construct(private readonly ?string $replacementVersion)
    {
    }

    public function dispatch(object $event): object
    {
        if (! $event instanceof ProfileBuiltEvent) {
            return $event;
        }

        $this->seenInput = $event->getInput();
        $this->seenProfile = $event->getProfile();

        if ($this->replacementVersion === null) {
            return $event;
        }

        $event->replaceProfile(new PlatformProfile(
            $this->replacementVersion,
            $this->seenProfile->services,
            $this->seenProfile->capabilities,
            $this->seenProfile->paymentHandlers,
            $this->seenProfile->signingKeys,
            $this->seenProfile->supportedVersions,
        ));

        return $event;
    }
}
