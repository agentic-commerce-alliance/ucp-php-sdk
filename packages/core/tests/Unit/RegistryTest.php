<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Contract\CapabilityInterface;
use Ucp\Sdk\Contract\PaymentHandlerInterface;
use Ucp\Sdk\Internal\Registry\CapabilityRegistry;
use Ucp\Sdk\Internal\Registry\PaymentHandlerRegistry;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;

final class RegistryTest extends TestCase
{
    #[Test]
    public function itFindsCapabilitiesByNameAndInterface(): void
    {
        $capability = new CountableTestCapability();

        $registry = new CapabilityRegistry([$capability]);

        self::assertSame([$capability], $registry->all());
        self::assertSame($capability, $registry->find('demo.capability'));
        self::assertNull($registry->find('missing'));
        self::assertSame($capability, $registry->firstImplementing(\Countable::class));
        self::assertNull($registry->firstImplementing(\Stringable::class));
    }

    #[Test]
    public function itRejectsDuplicateCapabilityNames(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Capability "demo.capability" is registered more than once.');

        new CapabilityRegistry([
            new CountableTestCapability(),
            new CountableTestCapability(),
        ]);
    }

    #[Test]
    public function itFindsPaymentHandlersByIdAndName(): void
    {
        $handler = $this->createMock(PaymentHandlerInterface::class);
        $handler
            ->method('id')
            ->willReturn('demo-handler');

        $registry = new PaymentHandlerRegistry([$handler]);

        self::assertSame([$handler], $registry->all());
        self::assertSame($handler, $registry->find('demo-handler'));
        self::assertNull($registry->find('missing'));
    }

    #[Test]
    public function itRejectsDuplicatePaymentHandlerIds(): void
    {
        $first = $this->createMock(PaymentHandlerInterface::class);
        $first
            ->method('id')
            ->willReturn('demo-handler');
        $second = $this->createMock(PaymentHandlerInterface::class);
        $second
            ->method('id')
            ->willReturn('demo-handler');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Payment handler "demo-handler" is registered more than once.');

        new PaymentHandlerRegistry([$first, $second]);
    }
}

final class CountableTestCapability implements CapabilityInterface, \Countable
{
    public function describe(): CapabilityDescriptor
    {
        return new CapabilityDescriptor('demo.capability', '2026-04-08', 'https://example.test/spec', 'https://example.test/schema');
    }

    public function count(): int
    {
        return 1;
    }
}
