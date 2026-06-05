<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Contract\CapabilityInterface;
use Ucp\Sdk\Contract\PaymentHandlerInterface;
use Ucp\Sdk\Internal\Registry\CapabilityRegistry;
use Ucp\Sdk\Internal\Registry\PaymentHandlerRegistry;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\Profile\PaymentHandlerDescriptor;
use Ucp\Sdk\Model\RequestContext;

final class RegistryTest extends TestCase
{
    #[Test]
    public function itFindsCapabilitiesByNameAndInterface(): void
    {
        $capability = new class () implements CapabilityInterface, \Countable {
            public function describe(): CapabilityDescriptor
            {
                return new CapabilityDescriptor('demo.capability', '2026-04-08', 'https://example.test/spec', 'https://example.test/schema');
            }

            public function count(): int
            {
                return 1;
            }
        };

        $registry = new CapabilityRegistry([$capability]);

        self::assertSame([$capability], $registry->all());
        self::assertSame($capability, $registry->find('demo.capability'));
        self::assertNull($registry->find('missing'));
        self::assertSame($capability, $registry->firstImplementing(\Countable::class));
        self::assertNull($registry->firstImplementing(\Stringable::class));
    }

    #[Test]
    public function itFindsPaymentHandlersByIdAndName(): void
    {
        $handler = new class () implements PaymentHandlerInterface {
            public function id(): string
            {
                return 'demo-handler';
            }

            public function describe(RequestContext $context): PaymentHandlerDescriptor
            {
                return new PaymentHandlerDescriptor($this->id(), 'Demo Handler', '2026-04-08', 'https://example.test/spec', 'https://example.test/schema', []);
            }

            public function prepareInstrument(PaymentInstrument $instrument, RequestContext $context): array
            {
                return ['paymentMethodId' => 'demo', 'token' => 'tok'];
            }

            public function supportsTokenization(): bool
            {
                return false;
            }

            public function tokenize(PaymentInstrument $instrument, RequestContext $context): ?array
            {
                return null;
            }
        };

        $registry = new PaymentHandlerRegistry([$handler]);

        self::assertSame([$handler], $registry->all());
        self::assertSame($handler, $registry->find('demo-handler'));
        self::assertNull($registry->find('missing'));
    }
}
