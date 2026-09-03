<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\Profile\CapabilityRequirements;
use Ucp\Sdk\Model\Profile\VersionRange;

final class CapabilityRequirementsTest extends TestCase
{
    #[Test]
    public function aDescriptorPublishesAndReadsBackItsRequirements(): void
    {
        $descriptor = new CapabilityDescriptor(
            name: 'dev.ucp.shopping.checkout',
            version: '2026-08-25',
            specUrl: 'https://ucp.dev/spec',
            schemaUrl: 'https://ucp.dev/schema.json',
            requires: new CapabilityRequirements(
                protocol: new VersionRange('2026-08-25'),
                capabilities: ['dev.ucp.shopping.cart' => new VersionRange('2026-04-08', '2026-08-25')],
            ),
        );

        $entry = $descriptor->toProfileEntry();

        self::assertSame([
            'protocol' => ['min' => '2026-08-25'],
            'capabilities' => ['dev.ucp.shopping.cart' => ['min' => '2026-04-08', 'max' => '2026-08-25']],
        ], $entry['requires']);

        $parsed = CapabilityDescriptor::fromProfileEntry('dev.ucp.shopping.checkout', $entry);

        self::assertEquals($descriptor->requires, $parsed->requires);
    }

    #[Test]
    public function aDescriptorWithoutRequirementsPublishesNoRequiresMember(): void
    {
        $entry = (new CapabilityDescriptor(
            name: 'dev.ucp.shopping.cart',
            version: '2026-04-08',
            specUrl: 'https://ucp.dev/spec',
            schemaUrl: 'https://ucp.dev/schema.json',
        ))->toProfileEntry();

        self::assertArrayNotHasKey('requires', $entry);
        self::assertNull(CapabilityDescriptor::fromProfileEntry('dev.ucp.shopping.cart', $entry)->requires);
    }

    #[Test]
    public function anEmptyRequirementsObjectIsNotPublishedEither(): void
    {
        // Emitting `"requires": {}` advertises a constraint that says nothing, and a peer
        // reading it has to decide whether the emptiness was deliberate.
        $entry = (new CapabilityDescriptor(
            name: 'dev.ucp.shopping.cart',
            version: '2026-04-08',
            specUrl: 'https://ucp.dev/spec',
            schemaUrl: 'https://ucp.dev/schema.json',
            requires: new CapabilityRequirements(),
        ))->toProfileEntry();

        self::assertArrayNotHasKey('requires', $entry);
    }

    #[Test]
    public function anUnparseableRangeIsRejectedWhenTheProfileIsParsed(): void
    {
        // Loudly, at parse time. A range nobody can evaluate is worse than an absent one: it
        // silently becomes "no constraint" for the rest of the session.
        $this->expectException(ValidationException::class);

        CapabilityDescriptor::fromProfileEntry('dev.ucp.shopping.cart', [
            'version' => '2026-04-08',
            'spec' => 'https://ucp.dev/spec',
            'schema' => 'https://ucp.dev/schema.json',
            'requires' => ['protocol' => ['min' => 'whenever']],
        ]);
    }

    #[Test]
    public function aNonObjectRequiresIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        CapabilityDescriptor::fromProfileEntry('dev.ucp.shopping.cart', [
            'version' => '2026-04-08',
            'spec' => 'https://ucp.dev/spec',
            'schema' => 'https://ucp.dev/schema.json',
            'requires' => 'protocol >= 2026-08-25',
        ]);
    }

    #[Test]
    public function requirementsWithoutAProtocolRangeAllowAnyProtocol(): void
    {
        self::assertTrue((new CapabilityRequirements())->allowsProtocol('2026-04-08'));
        self::assertTrue((new CapabilityRequirements())->isEmpty());
    }
}
