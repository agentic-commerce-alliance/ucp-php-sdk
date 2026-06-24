<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Contract\CartCapabilityInterface;
use Ucp\Sdk\Contract\CatalogCapabilityInterface;
use Ucp\Sdk\Contract\CheckoutCapabilityInterface;
use Ucp\Sdk\Contract\DiscountCapabilityInterface;
use Ucp\Sdk\Contract\IdentityLinkingCapabilityInterface;
use Ucp\Sdk\Contract\OrderCapabilityInterface;
use Ucp\Sdk\Contract\PaymentHandlerInterface;
use Ucp\Sdk\Contract\TokenizationCapabilityInterface;
use Ucp\Sdk\Internal\Negotiation\DefaultCapabilityNegotiator;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\Profile\PaymentHandlerDescriptor;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Service\CapabilityRegistryInterface;
use Ucp\Sdk\Service\PaymentHandlerRegistryInterface;

final class DefaultCapabilityNegotiatorTest extends TestCase
{
    private DefaultCapabilityNegotiator $negotiator;

    protected function setUp(): void
    {
        $catalogCapability = $this->createMock(CatalogCapabilityInterface::class);
        $catalogCapability
            ->method('describe')
            ->willReturn(new CapabilityDescriptor('dev.ucp.shopping.catalog', '2026-04-08', 'https://example.test/spec/catalog', 'https://example.test/schema/catalog'));
        $cartCapability = $this->createMock(CartCapabilityInterface::class);
        $cartCapability
            ->method('describe')
            ->willReturn(new CapabilityDescriptor('dev.ucp.shopping.cart', '2026-04-08', 'https://example.test/spec/cart', 'https://example.test/schema/cart'));
        $checkoutCapability = $this->createMock(CheckoutCapabilityInterface::class);
        $checkoutCapability
            ->method('describe')
            ->willReturn(new CapabilityDescriptor('dev.ucp.shopping.checkout', '2026-04-08', 'https://example.test/spec/checkout', 'https://example.test/schema/checkout'));
        $discountCapability = $this->createMock(DiscountCapabilityInterface::class);
        $discountCapability
            ->method('describe')
            ->willReturn(new CapabilityDescriptor('dev.ucp.shopping.discount', '2026-04-08', 'https://example.test/spec/discount', 'https://example.test/schema/discount'));
        $identityCapability = $this->createMock(IdentityLinkingCapabilityInterface::class);
        $identityCapability
            ->method('describe')
            ->willReturn(new CapabilityDescriptor('dev.ucp.identity.oauth', '2026-04-08', 'https://example.test/spec/oauth', 'https://example.test/schema/oauth'));
        $tokenizationCapability = $this->createMock(TokenizationCapabilityInterface::class);
        $tokenizationCapability
            ->method('describe')
            ->willReturn(new CapabilityDescriptor('dev.ucp.payment.tokenization', '2026-04-08', 'https://example.test/spec/tokenization', 'https://example.test/schema/tokenization'));
        $orderCapability = $this->createMock(OrderCapabilityInterface::class);
        $orderCapability
            ->method('describe')
            ->willReturn(new CapabilityDescriptor('dev.ucp.shopping.order', '2026-04-08', 'https://example.test/spec/order', 'https://example.test/schema/order'));
        $capabilityRegistry = $this->createMock(CapabilityRegistryInterface::class);
        $capabilityRegistry
            ->method('all')
            ->willReturn([
                $catalogCapability,
                $cartCapability,
                $checkoutCapability,
                $discountCapability,
                $identityCapability,
                $tokenizationCapability,
                $orderCapability,
            ]);

        $cardHandler = $this->createMock(PaymentHandlerInterface::class);
        $cardHandler
            ->method('id')
            ->willReturn('handler-1');
        $cardHandler
            ->method('describe')
            ->willReturn(new PaymentHandlerDescriptor('handler-1', 'Card', '2026-04-08', 'https://merchant.example/spec/card', 'https://merchant.example/schema/card', []));
        $walletHandler = $this->createMock(PaymentHandlerInterface::class);
        $walletHandler
            ->method('id')
            ->willReturn('handler-2');
        $walletHandler
            ->method('describe')
            ->willReturn(new PaymentHandlerDescriptor('handler-2', 'com.merchant.wallet', '2026-04-08', 'https://merchant.example/spec/wallet', 'https://merchant.example/schema/wallet', [], ['merchant_id' => 'merchant-1']));
        $paymentHandlerRegistry = $this->createMock(PaymentHandlerRegistryInterface::class);
        $paymentHandlerRegistry
            ->method('all')
            ->willReturn([$cardHandler, $walletHandler]);

        $this->negotiator = new DefaultCapabilityNegotiator(
            $capabilityRegistry,
            $paymentHandlerRegistry,
        );
    }

    #[Test]
    public function itIntersectsCapabilitiesAndPaymentHandlers(): void
    {
        $platformProfile = new PlatformProfile(
            '2026-04-08',
            [],
            [
                'dev.ucp.shopping.checkout' => [
                    new CapabilityDescriptor('dev.ucp.shopping.checkout', '2026-04-08', 'https://platform.example/spec/checkout', 'https://platform.example/schema/checkout'),
                ],
                'dev.ucp.shopping.discount' => [
                    new CapabilityDescriptor('dev.ucp.shopping.discount', '2026-04-08', 'https://platform.example/spec/discount', 'https://platform.example/schema/discount', ['dev.ucp.shopping.checkout']),
                ],
                'dev.ucp.shopping.loyalty' => [
                    new CapabilityDescriptor('dev.ucp.shopping.loyalty', '2026-04-08', 'https://platform.example/spec/loyalty', 'https://platform.example/schema/loyalty', ['dev.ucp.shopping.catalog']),
                ],
            ],
            [
                'payments' => [
                    new PaymentHandlerDescriptor('handler-2', 'Wallet', '2026-04-08', 'https://platform.example/spec/wallet', 'https://platform.example/schema/wallet', []),
                    new PaymentHandlerDescriptor('handler-3', 'Voucher', '2026-04-08', 'https://platform.example/spec/voucher', 'https://platform.example/schema/voucher', []),
                ],
            ],
        );

        $result = $this->negotiator->negotiate($platformProfile, new RequestContext('merchant.example'));

        self::assertSame(['dev.ucp.shopping.checkout', 'dev.ucp.shopping.discount'], $result->capabilityNames());
        self::assertSame(['handler-2'], $result->paymentHandlerIds);
        self::assertSame(['com.merchant.wallet'], array_keys($result->paymentHandlers));
        self::assertSame('handler-2', $result->paymentHandlers['com.merchant.wallet'][0]->id);
        self::assertSame('https://merchant.example/spec/wallet', $result->paymentHandlers['com.merchant.wallet'][0]->specUrl);
        self::assertSame(['dev.ucp.shopping.checkout', 'dev.ucp.shopping.discount'], $result->capabilitiesForOperation('checkout.create'));
        self::assertSame([], $result->capabilitiesForOperation('order.get'));
    }

    #[Test]
    public function itBuildsOperationMapsForEverySupportedCapabilityFamily(): void
    {
        $platformProfile = new PlatformProfile(
            '2026-04-08',
            [],
            [
                'dev.ucp.shopping.catalog' => [new CapabilityDescriptor('dev.ucp.shopping.catalog', '2026-04-08', 'https://platform.example/spec/catalog', 'https://platform.example/schema/catalog')],
                'dev.ucp.shopping.cart' => [new CapabilityDescriptor('dev.ucp.shopping.cart', '2026-04-08', 'https://platform.example/spec/cart', 'https://platform.example/schema/cart')],
                'dev.ucp.shopping.checkout' => [new CapabilityDescriptor('dev.ucp.shopping.checkout', '2026-04-08', 'https://platform.example/spec/checkout', 'https://platform.example/schema/checkout')],
                'dev.ucp.shopping.discount' => [new CapabilityDescriptor('dev.ucp.shopping.discount', '2026-04-08', 'https://platform.example/spec/discount', 'https://platform.example/schema/discount')],
                'dev.ucp.identity.oauth' => [new CapabilityDescriptor('dev.ucp.identity.oauth', '2026-04-08', 'https://platform.example/spec/oauth', 'https://platform.example/schema/oauth')],
                'dev.ucp.payment.tokenization' => [new CapabilityDescriptor('dev.ucp.payment.tokenization', '2026-04-08', 'https://platform.example/spec/tokenization', 'https://platform.example/schema/tokenization')],
                'dev.ucp.shopping.order' => [new CapabilityDescriptor('dev.ucp.shopping.order', '2026-04-08', 'https://platform.example/spec/order', 'https://platform.example/schema/order')],
            ],
            [],
        );

        $result = $this->negotiator->negotiate($platformProfile, new RequestContext('merchant.example'));

        self::assertSame(['dev.ucp.shopping.catalog'], $result->capabilitiesForOperation('catalog.search'));
        self::assertSame(['dev.ucp.shopping.catalog'], $result->capabilitiesForOperation('catalog.product'));
        self::assertSame(['dev.ucp.shopping.cart', 'dev.ucp.shopping.discount'], $result->capabilitiesForOperation('cart.create'));
        self::assertSame(['dev.ucp.shopping.checkout', 'dev.ucp.shopping.discount'], $result->capabilitiesForOperation('checkout.update'));
        self::assertSame(['dev.ucp.identity.oauth'], $result->capabilitiesForOperation('oauth.token'));
        self::assertSame(['dev.ucp.payment.tokenization'], $result->capabilitiesForOperation('tokenization'));
        self::assertSame(['dev.ucp.shopping.order'], $result->capabilitiesForOperation('order.get'));
    }

    #[Test]
    public function itOnlyNegotiatesEnabledLocalCapabilitiesWhenAnAllowlistIsConfigured(): void
    {
        $platformProfile = new PlatformProfile(
            '2026-04-08',
            [],
            [
                'dev.ucp.shopping.cart' => [new CapabilityDescriptor('dev.ucp.shopping.cart', '2026-04-08', 'https://platform.example/spec/cart', 'https://platform.example/schema/cart')],
                'dev.ucp.shopping.checkout' => [new CapabilityDescriptor('dev.ucp.shopping.checkout', '2026-04-08', 'https://platform.example/spec/checkout', 'https://platform.example/schema/checkout')],
                'dev.ucp.shopping.discount' => [new CapabilityDescriptor('dev.ucp.shopping.discount', '2026-04-08', 'https://platform.example/spec/discount', 'https://platform.example/schema/discount')],
            ],
            [],
        );
        $context = new RequestContext(
            'merchant.example',
            runtimeConfiguration: new RuntimeConfiguration(
                '2026-04-08',
                'https://merchant.example',
                enabledCapabilities: ['dev.ucp.shopping.checkout'],
            ),
        );

        $result = $this->negotiator->negotiate($platformProfile, $context);

        self::assertSame(['dev.ucp.shopping.checkout'], $result->capabilityNames());
        self::assertSame([], $result->capabilitiesForOperation('cart.create'));
        self::assertSame(['dev.ucp.shopping.checkout'], $result->capabilitiesForOperation('checkout.create'));
    }
}
