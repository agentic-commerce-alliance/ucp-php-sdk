<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Enum\Transport;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Internal\Security\DefaultSigningKeyManager;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Model\Profile\ServiceEndpoint;

final class PlatformProfileTest extends TestCase
{
    #[Test]
    public function itSerializesCanonicalDiscoveryProfiles(): void
    {
        $profile = new PlatformProfile(
            '2026-04-08',
            [
                'dev.ucp.shopping' => [
                    new ServiceEndpoint(
                        Transport::Rest,
                        'https://shop.example/ucp/v1',
                        '2026-04-08',
                        'https://ucp.dev/specification/overview/',
                        'https://ucp.dev/2026-04-08/services/shopping/rest.openapi.json',
                    ),
                ],
            ],
            [
                'dev.ucp.shopping.cart' => [
                    new CapabilityDescriptor(
                        'dev.ucp.shopping.cart',
                        '2026-04-08',
                        'https://ucp.dev/specification/cart/',
                        'https://ucp.dev/schemas/shopping/cart.json',
                    ),
                ],
            ],
            [],
        );

        $payload = $profile->toArray();

        self::assertSame(['ucp', 'signing_keys'], array_keys($payload));
        self::assertArrayNotHasKey('version', $payload);
        self::assertArrayNotHasKey('services', $payload);
        self::assertArrayNotHasKey('capabilities', $payload);
        self::assertArrayNotHasKey('payment_handlers', $payload);
        self::assertArrayNotHasKey('supported_versions', $payload);
        self::assertArrayNotHasKey('supported_versions', $payload['ucp']);
        self::assertSame('https://ucp.dev/2026-04-08/services/shopping/rest.openapi.json', $payload['ucp']['services']['dev.ucp.shopping'][0]['schema']);
        self::assertInstanceOf(\stdClass::class, $payload['ucp']['payment_handlers']);
    }

    #[Test]
    public function itSerializesEmptyMapsAsJsonObjects(): void
    {
        $payload = (new PlatformProfile('2026-04-08', [], [], []))->toArray();

        self::assertInstanceOf(\stdClass::class, $payload['ucp']['services']);
        self::assertInstanceOf(\stdClass::class, $payload['ucp']['capabilities']);
        self::assertInstanceOf(\stdClass::class, $payload['ucp']['payment_handlers']);
        self::assertSame(
            '{"ucp":{"version":"2026-04-08","services":{},"capabilities":{},"payment_handlers":{}},"signing_keys":[]}',
            json_encode($payload, \JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function itSerializesRealOlderSupportedVersionsInsideTheUcpEnvelope(): void
    {
        $payload = (new PlatformProfile(
            '2026-04-08',
            [],
            [],
            [],
            [],
            ['2025-10-01' => 'https://shop.example/.well-known/ucp/2025-10-01'],
        ))->toArray();

        self::assertArrayNotHasKey('supported_versions', $payload);
        self::assertSame(
            ['2025-10-01' => 'https://shop.example/.well-known/ucp/2025-10-01'],
            $payload['ucp']['supported_versions'],
        );
    }

    #[Test]
    public function itParsesLegacyMirroredProfiles(): void
    {
        $profile = PlatformProfile::fromArray([
            'ucp' => ['version' => '2026-04-08'],
            'services' => [
                'dev.ucp.shopping' => [[
                    'transport' => 'rest',
                    'endpoint' => 'https://shop.example/ucp/v1',
                    'version' => '2026-04-08',
                    'spec' => 'https://ucp.dev/specification/overview/',
                    'schema' => 'https://ucp.dev/2026-04-08/services/shopping/rest.openapi.json',
                ]],
            ],
            'capabilities' => [
                'dev.ucp.shopping.cart' => [[
                    'version' => '2026-04-08',
                    'spec' => 'https://ucp.dev/specification/cart/',
                    'schema' => 'https://ucp.dev/schemas/shopping/cart.json',
                ]],
            ],
            'payment_handlers' => [],
            'signing_keys' => [],
            'supported_versions' => [
                '2025-10-01' => 'https://shop.example/.well-known/ucp/2025-10-01',
            ],
        ]);

        self::assertSame('2026-04-08', $profile->version);
        self::assertArrayHasKey('dev.ucp.shopping', $profile->services);
        self::assertSame('https://ucp.dev/2026-04-08/services/shopping/rest.openapi.json', $profile->services['dev.ucp.shopping'][0]->schemaUrl);
        self::assertArrayHasKey('dev.ucp.shopping.cart', $profile->capabilities);
        self::assertSame(['2025-10-01' => 'https://shop.example/.well-known/ucp/2025-10-01'], $profile->supportedVersions);
    }

    #[Test]
    public function itRejectsProfilesWithoutAProtocolVersion(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Platform profile version must be a non-empty string.');

        PlatformProfile::fromArray([
            'ucp' => [
                'services' => [],
                'capabilities' => [],
                'payment_handlers' => [],
            ],
            'signing_keys' => [],
        ]);
    }

    #[Test]
    public function itRejectsMalformedProfileSectionsInsteadOfDroppingThem(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Platform profile section "services" must be an object.');

        PlatformProfile::fromArray([
            'ucp' => [
                'version' => '2026-04-08',
                'services' => 'bad',
                'capabilities' => [],
                'payment_handlers' => [],
            ],
            'signing_keys' => [],
        ]);
    }

    #[Test]
    public function itRejectsMalformedEntriesInsideProfileSections(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Platform profile section "capabilities" entry "dev.ucp.shopping.cart" at index 0 must be an object.');

        PlatformProfile::fromArray([
            'ucp' => [
                'version' => '2026-04-08',
                'services' => [],
                'capabilities' => [
                    'dev.ucp.shopping.cart' => ['not-an-object'],
                ],
                'payment_handlers' => [],
            ],
            'signing_keys' => [],
        ]);
    }

    #[Test]
    public function itRejectsDuplicateSigningKeyIdsInProfiles(): void
    {
        $manager = new DefaultSigningKeyManager();
        $jwk = $manager->toPublicKey($manager->generate('kid-1'))->toJwk();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Platform profile signing key id "kid-1" is duplicated.');

        PlatformProfile::fromArray([
            'ucp' => [
                'version' => '2026-04-08',
                'services' => [],
                'capabilities' => [],
                'payment_handlers' => [],
            ],
            'signing_keys' => [
                $jwk,
                $jwk,
            ],
        ]);
    }
}
