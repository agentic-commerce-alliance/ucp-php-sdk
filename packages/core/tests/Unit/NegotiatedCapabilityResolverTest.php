<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Internal\Negotiation\NegotiatedCapabilityResolver;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\Profile\CapabilityRequirements;
use Ucp\Sdk\Model\Profile\VersionRange;

final class NegotiatedCapabilityResolverTest extends TestCase
{
    private const PROTOCOL = '2026-04-08';

    #[Test]
    public function aCapabilityBothSidesKnowAtTheSameVersionSurvives(): void
    {
        $result = $this->resolve(
            ['dev.ucp.shopping.cart' => [$this->descriptor('dev.ucp.shopping.cart')]],
            ['dev.ucp.shopping.cart' => $this->descriptor('dev.ucp.shopping.cart')],
        );

        self::assertSame(['dev.ucp.shopping.cart'], array_keys($result));
    }

    #[Test]
    public function aVersionMismatchExcludesTheCapability(): void
    {
        // Matching on name alone selected this, invoked it, and failed later with an error
        // about a field rather than about the version.
        $result = $this->resolve(
            ['dev.ucp.shopping.cart' => [$this->descriptor('dev.ucp.shopping.cart', version: '2026-08-25')]],
            ['dev.ucp.shopping.cart' => $this->descriptor('dev.ucp.shopping.cart', version: '2026-04-08')],
        );

        self::assertSame([], $result);
    }

    #[Test]
    public function theEntryMatchingOurVersionIsKeptWhenSeveralAreOffered(): void
    {
        $result = $this->resolve(
            ['dev.ucp.shopping.cart' => [
                $this->descriptor('dev.ucp.shopping.cart', version: '2026-08-25'),
                $this->descriptor('dev.ucp.shopping.cart', version: '2026-04-08'),
            ]],
            ['dev.ucp.shopping.cart' => $this->descriptor('dev.ucp.shopping.cart', version: '2026-04-08')],
        );

        self::assertCount(1, $result['dev.ucp.shopping.cart']);
        self::assertSame('2026-04-08', $result['dev.ucp.shopping.cart'][0]->version);
    }

    #[Test]
    public function anAbsentDependencyExcludesTheWholeChain(): void
    {
        // A extends B extends C, and C is not offered. A single filtering pass drops B but
        // tests A against the name set as it was before B was dropped, so A survives holding a
        // dependency that is gone. This is the case that pass caught nothing on.
        $a = $this->descriptor('com.example.a', extends: ['com.example.b']);
        $b = $this->descriptor('com.example.b', extends: ['com.example.c']);

        $result = $this->resolve(
            ['com.example.a' => [$a], 'com.example.b' => [$b]],
            ['com.example.a' => $a, 'com.example.b' => $b],
        );

        self::assertSame([], $result, 'both A and B depend, transitively, on the missing C');
    }

    #[Test]
    public function aPresentDependencyChainSurvivesIntact(): void
    {
        $a = $this->descriptor('com.example.a', extends: ['com.example.b']);
        $b = $this->descriptor('com.example.b', extends: ['com.example.c']);
        $c = $this->descriptor('com.example.c');

        $offered = ['com.example.a' => [$a], 'com.example.b' => [$b], 'com.example.c' => [$c]];
        $result = $this->resolve($offered, ['com.example.a' => $a, 'com.example.b' => $b, 'com.example.c' => $c]);

        self::assertSame(['com.example.a', 'com.example.b', 'com.example.c'], array_keys($result));
    }

    #[Test]
    public function aRequiredCapabilityVersionOutsideTheRangeExcludesTheDependent(): void
    {
        $cart = $this->descriptor('dev.ucp.shopping.cart', version: '2026-04-08');
        $checkout = $this->descriptor('dev.ucp.shopping.checkout', requires: new CapabilityRequirements(
            capabilities: ['dev.ucp.shopping.cart' => new VersionRange('2026-08-25')],
        ));

        $result = $this->resolve(
            ['dev.ucp.shopping.cart' => [$cart], 'dev.ucp.shopping.checkout' => [$checkout]],
            ['dev.ucp.shopping.cart' => $this->descriptor('dev.ucp.shopping.cart', version: '2026-04-08'), 'dev.ucp.shopping.checkout' => $this->descriptor('dev.ucp.shopping.checkout')],
        );

        self::assertSame(['dev.ucp.shopping.cart'], array_keys($result));
    }

    #[Test]
    public function aRequiredCapabilityVersionInsideTheRangeIsAccepted(): void
    {
        $cart = $this->descriptor('dev.ucp.shopping.cart');
        $checkout = $this->descriptor('dev.ucp.shopping.checkout', requires: new CapabilityRequirements(
            capabilities: ['dev.ucp.shopping.cart' => new VersionRange('2026-01-01', '2026-12-31')],
        ));

        $result = $this->resolve(
            ['dev.ucp.shopping.cart' => [$cart], 'dev.ucp.shopping.checkout' => [$checkout]],
            ['dev.ucp.shopping.cart' => $cart, 'dev.ucp.shopping.checkout' => $this->descriptor('dev.ucp.shopping.checkout')],
        );

        self::assertSame(['dev.ucp.shopping.cart', 'dev.ucp.shopping.checkout'], array_keys($result));
    }

    #[Test]
    public function aProtocolRangeThatExcludesTheNegotiatedVersionExcludesTheCapability(): void
    {
        $descriptor = $this->descriptor('dev.ucp.shopping.cart', requires: new CapabilityRequirements(
            protocol: new VersionRange('2026-08-25'),
        ));

        $result = $this->resolve(
            ['dev.ucp.shopping.cart' => [$descriptor]],
            ['dev.ucp.shopping.cart' => $this->descriptor('dev.ucp.shopping.cart')],
        );

        self::assertSame([], $result);
    }

    #[Test]
    public function ourOwnProtocolRequirementExcludesTheCapabilityToo(): void
    {
        // Symmetry matters: if we declare a capability needs a protocol version we are not
        // running, offering it is our bug, not the peer's.
        $result = $this->resolve(
            ['dev.ucp.shopping.cart' => [$this->descriptor('dev.ucp.shopping.cart')]],
            ['dev.ucp.shopping.cart' => $this->descriptor('dev.ucp.shopping.cart', requires: new CapabilityRequirements(
                protocol: new VersionRange('2026-08-25'),
            ))],
        );

        self::assertSame([], $result);
    }

    #[Test]
    public function withoutANegotiatedProtocolVersionProtocolRangesAreNotTested(): void
    {
        // No runtime configuration means no version to test against, and inventing one would
        // exclude capabilities on a guess.
        $descriptor = $this->descriptor('dev.ucp.shopping.cart', requires: new CapabilityRequirements(
            protocol: new VersionRange('2026-08-25'),
        ));

        $result = (new NegotiatedCapabilityResolver())->resolve(
            ['dev.ucp.shopping.cart' => [$descriptor]],
            ['dev.ucp.shopping.cart' => $this->descriptor('dev.ucp.shopping.cart')],
            null,
        );

        self::assertSame(['dev.ucp.shopping.cart'], array_keys($result));
    }

    #[Test]
    public function aNameWeDoNotImplementIsIgnored(): void
    {
        $result = $this->resolve(
            ['com.example.unknown' => [$this->descriptor('com.example.unknown')]],
            [],
        );

        self::assertSame([], $result);
    }


    #[Test]
    public function bothSidesRequirementRangesMustOverlap(): void
    {
        // We need the cart no earlier than 2026-08-25; the peer needs it no later than
        // 2026-04-08. No version satisfies both, so the dependent is excluded whatever the peer
        // happens to be offering.
        $cart = $this->descriptor('dev.ucp.shopping.cart');
        $remoteCheckout = $this->descriptor('dev.ucp.shopping.checkout', requires: new CapabilityRequirements(
            capabilities: ['dev.ucp.shopping.cart' => new VersionRange('2026-01-01', '2026-04-08')],
        ));
        $localCheckout = $this->descriptor('dev.ucp.shopping.checkout', requires: new CapabilityRequirements(
            capabilities: ['dev.ucp.shopping.cart' => new VersionRange('2026-08-25')],
        ));

        $result = $this->resolve(
            ['dev.ucp.shopping.cart' => [$cart], 'dev.ucp.shopping.checkout' => [$remoteCheckout]],
            ['dev.ucp.shopping.cart' => $cart, 'dev.ucp.shopping.checkout' => $localCheckout],
        );

        self::assertSame(['dev.ucp.shopping.cart'], array_keys($result));
    }

    #[Test]
    public function overlappingRequirementRangesNarrowRatherThanExclude(): void
    {
        // Individually each side would accept the offered 2026-04-08 cart. Their overlap does
        // too, so narrowing must not turn two satisfiable requirements into an exclusion.
        $cart = $this->descriptor('dev.ucp.shopping.cart', version: '2026-04-08');
        $remoteCheckout = $this->descriptor('dev.ucp.shopping.checkout', requires: new CapabilityRequirements(
            capabilities: ['dev.ucp.shopping.cart' => new VersionRange('2026-01-01', '2026-06-01')],
        ));
        $localCheckout = $this->descriptor('dev.ucp.shopping.checkout', requires: new CapabilityRequirements(
            capabilities: ['dev.ucp.shopping.cart' => new VersionRange('2026-03-01')],
        ));

        $result = $this->resolve(
            ['dev.ucp.shopping.cart' => [$cart], 'dev.ucp.shopping.checkout' => [$remoteCheckout]],
            ['dev.ucp.shopping.cart' => $cart, 'dev.ucp.shopping.checkout' => $localCheckout],
        );

        self::assertSame(['dev.ucp.shopping.cart', 'dev.ucp.shopping.checkout'], array_keys($result));
    }

    /**
     * @param array<string, list<CapabilityDescriptor>> $offered
     * @param array<string, CapabilityDescriptor> $local
     *
     * @return array<string, list<CapabilityDescriptor>>
     */
    private function resolve(array $offered, array $local): array
    {
        return (new NegotiatedCapabilityResolver())->resolve($offered, $local, self::PROTOCOL);
    }

    /**
     * @param list<string>|null $extends
     */
    private function descriptor(
        string $name,
        string $version = self::PROTOCOL,
        ?array $extends = null,
        ?CapabilityRequirements $requires = null,
    ): CapabilityDescriptor {
        return new CapabilityDescriptor(
            name: $name,
            version: $version,
            specUrl: 'https://ucp.dev/spec',
            schemaUrl: 'https://ucp.dev/schema.json',
            extends: $extends,
            requires: $requires,
        );
    }
}
