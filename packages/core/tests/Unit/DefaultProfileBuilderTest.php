<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Ucp\Sdk\Contract\CapabilityInterface;
use Ucp\Sdk\Contract\PaymentHandlerInterface;
use Ucp\Sdk\Internal\Registry\CapabilityRegistry;
use Ucp\Sdk\Internal\Registry\PaymentHandlerRegistry;
use Ucp\Sdk\Internal\Service\DefaultProfileBuilder;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\Profile\PaymentHandlerDescriptor;
use Ucp\Sdk\Model\Profile\ProfileBuildInput;
use Ucp\Sdk\Model\RequestContext;

final class DefaultProfileBuilderTest extends TestCase
{
    public function testItBuildsDiscoveryProfiles(): void
    {
        $capability = new class () implements CapabilityInterface {
            public function describe(): CapabilityDescriptor
            {
                return new CapabilityDescriptor('dev.ucp.shopping.checkout', '2026-04-08', 'https://ucp.dev/specification/checkout/', 'https://ucp.dev/schemas/shopping/checkout.json');
            }
        };

        $handler = new class () implements PaymentHandlerInterface {
            public function id(): string
            {
                return 'demo';
            }

            public function describe(RequestContext $context): PaymentHandlerDescriptor
            {
                return new PaymentHandlerDescriptor($this->id(), 'com.demo.tokenizer', '2026-04-08', 'https://ucp.dev/specification/payment-handler-guide/', 'https://ucp.dev/schemas/payments/delegate-payment.json', ['schema']);
            }

            public function prepareInstrument(PaymentInstrument $instrument, RequestContext $context): array
            {
                return ['paymentMethodId' => 'demo', 'token' => 'demo'];
            }

            public function supportsTokenization(): bool
            {
                return true;
            }

            public function tokenize(PaymentInstrument $instrument, RequestContext $context): ?array
            {
                if ($instrument->handlerId !== 'demo') {
                    return null;
                }

                return ['token' => 'demo'];
            }
        };

        $builder = new DefaultProfileBuilder(
            new CapabilityRegistry([$capability]),
            new PaymentHandlerRegistry([$handler]),
            [],
            [],
            new EventDispatcher(),
        );

        $profile = $builder->build(new ProfileBuildInput('2026-04-08', 'https://shop.example'));

        self::assertSame('2026-04-08', $profile->version);
        self::assertArrayHasKey('dev.ucp.shopping.checkout', $profile->capabilities);
        self::assertArrayHasKey('com.demo.tokenizer', $profile->paymentHandlers);
    }
}
