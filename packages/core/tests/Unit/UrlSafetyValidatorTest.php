<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Internal\Service\UrlSafetyValidator;

final class UrlSafetyValidatorTest extends TestCase
{
    #[Test]
    public function itAllowsHttpsHostsOnTheAllowlist(): void
    {
        self::expectNotToPerformAssertions();

        $validator = new UrlSafetyValidator(['127.0.0.1']);

        $validator->assertAllowed('https://127.0.0.1/.well-known/ucp');
    }

    #[Test]
    public function itRejectsPlainHttpForLocalDevelopmentHostsByDefault(): void
    {
        $validator = new UrlSafetyValidator();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Plain http is only allowed when profile fetching development mode is enabled.');

        $validator->assertAllowed('http://localhost/.well-known/ucp');
    }

    #[Test]
    public function itAllowsPlainHttpForLocalDevelopmentHostsWhenDevelopmentModeIsEnabled(): void
    {
        self::expectNotToPerformAssertions();

        $validator = new UrlSafetyValidator([], null, true);

        $validator->assertAllowed('http://localhost/.well-known/ucp');
    }

    #[Test]
    public function itRejectsPublicHostsWhenNoAllowlistIsConfigured(): void
    {
        $validator = new UrlSafetyValidator();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Profile host "8.8.8.8" is not allowed.');

        $validator->assertAllowed('https://8.8.8.8/.well-known/ucp');
    }

    #[Test]
    public function itRejectsUnsupportedSchemes(): void
    {
        $validator = new UrlSafetyValidator();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Profile URI must use http or https.');

        $validator->assertAllowed('ftp://127.0.0.1/.well-known/ucp');
    }

    #[Test]
    public function itRejectsUserinfo(): void
    {
        $validator = new UrlSafetyValidator();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Profile URI must not include userinfo.');

        $validator->assertAllowed('https://user:pass@127.0.0.1/.well-known/ucp');
    }

    #[Test]
    public function itRejectsBlockedMetadataHosts(): void
    {
        $validator = new UrlSafetyValidator();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Profile host "169.254.169.254" is blocked.');

        $validator->assertAllowed('https://169.254.169.254/latest/meta-data');
    }

    #[Test]
    public function itRejectsPlainHttpForNonLocalHosts(): void
    {
        $validator = new UrlSafetyValidator();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Plain http is only allowed for local development hosts.');

        $validator->assertAllowed('http://8.8.8.8/.well-known/ucp');
    }

    #[Test]
    public function itRejectsBlockedIpRanges(): void
    {
        $validator = new UrlSafetyValidator();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Profile host "10.0.0.1" resolves to a blocked IP address.');

        $validator->assertAllowed('https://10.0.0.1/.well-known/ucp');
    }

    /**
     * The special-use ranges PHP's filter flags do not cover, and the IPv6 forms that embed an
     * IPv4 address. Each is judged by where the connection would end up. Under `all` these
     * are the only thing between an unsigned request and an outbound connection.
     *
     * @return iterable<string, array{string}>
     */
    public static function specialUseAddresses(): iterable
    {
        yield 'shared address space (CGNAT)' => ['100.64.0.1'];
        yield 'IETF protocol assignments' => ['192.0.0.1'];
        yield 'benchmarking' => ['198.18.0.1'];
        yield 'documentation TEST-NET-3' => ['203.0.113.10'];
        yield 'multicast' => ['224.0.0.1'];
        yield 'limited broadcast' => ['255.255.255.255'];
        yield 'site-local IPv6' => ['fec0::1'];
        yield 'IPv6 documentation' => ['2001:db8::1'];
        yield 'IPv6 documentation (RFC 9637)' => ['3fff::1'];
        yield 'SRv6 segment identifiers' => ['5f00::1'];
        yield 'IPv4-mapped private' => ['::ffff:10.0.0.1'];
        yield 'IPv4-mapped metadata' => ['::ffff:169.254.169.254'];
        yield 'IPv4-mapped loopback' => ['::ffff:127.0.0.1'];
        yield 'IPv4-compatible metadata' => ['::a9fe:a9fe'];
        yield 'NAT64 well-known prefix embedding metadata' => ['64:ff9b::a9fe:a9fe'];
        yield 'NAT64 local-use prefix embedding metadata at the /96 position' => ['64:ff9b:1::a9fe:a9fe'];
        yield 'NAT64 local-use prefix embedding metadata at the /48 position' => ['64:ff9b:1:a9fe:a9:fe00:5db8:d822'];
        yield 'NAT64 local-use prefix embedding loopback at the /48 position' => ['64:ff9b:1:7f00:0:100:5db8:d822'];
        yield 'IPv4-translatable (SIIT) embedding metadata' => ['::ffff:0:a9fe:a9fe'];
        yield '6to4 embedding metadata' => ['2002:a9fe:a9fe::1'];
    }

    #[Test]
    #[DataProvider('specialUseAddresses')]
    public function itRejectsASpecialUseAddressTheHostResolvesTo(string $address): void
    {
        $validator = new UrlSafetyValidator([], static fn (string $host): array => [$address]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Profile host "platform.example" resolves to a blocked IP address.');

        $validator->validateAndResolve('https://platform.example/.well-known/ucp', null, true);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function publicAddresses(): iterable
    {
        yield 'public IPv4' => ['93.184.216.34'];
        yield 'public IPv6' => ['2606:4700::1111'];
        yield 'IPv4-mapped public' => ['::ffff:8.8.8.8'];
        yield 'NAT64 embedding a public address' => ['64:ff9b::808:808'];
        yield '6to4 embedding a public address' => ['2002:808:808::1'];
    }

    #[Test]
    #[DataProvider('publicAddresses')]
    public function itAcceptsAPublicAddressTheHostResolvesTo(string $address): void
    {
        $validator = new UrlSafetyValidator([], static fn (string $host): array => [$address]);

        $validated = $validator->validateAndResolve('https://platform.example/.well-known/ucp', null, true);

        self::assertSame($address, $validated->resolvedIp);
    }

    #[Test]
    public function itRejectsHostsOutsideTheAllowlist(): void
    {
        $validator = new UrlSafetyValidator(['127.0.0.1']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Profile host "8.8.8.8" is not allowed.');

        $validator->assertAllowed('https://8.8.8.8/.well-known/ucp');
    }

    #[Test]
    public function itRejectsDisallowedRemotePorts(): void
    {
        $validator = new UrlSafetyValidator();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Profile port "11211" is not allowed.');

        $validator->assertAllowed('https://8.8.8.8:11211/.well-known/ucp');
    }

    #[Test]
    public function itRejectsBlockedIpv6AddressesResolvedThroughDns(): void
    {
        $validator = new UrlSafetyValidator(
            ['platform.example'],
            static fn (string $host): array => $host === 'platform.example' ? ['::1'] : [],
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Profile host "platform.example" resolves to a blocked IP address.');

        $validator->assertAllowed('https://platform.example/.well-known/ucp');
    }

    #[Test]
    public function itAllowsHostsFromThePerCallOverrideEvenWhenAbsentFromTheConstructorAllowlist(): void
    {
        self::expectNotToPerformAssertions();

        // Empty constructor allowlist would reject any public host; the per-call
        // override (e.g. a resolved sales channel's allowed profile hosts) wins.
        $validator = new UrlSafetyValidator(
            [],
            static fn (string $host): array => $host === 'platform.example' ? ['93.184.216.34'] : [],
        );

        $validator->validateAndResolve('https://platform.example/.well-known/ucp', ['platform.example']);
    }

    #[Test]
    public function itRejectsHostsAbsentFromThePerCallOverrideEvenWhenOnTheConstructorAllowlist(): void
    {
        // The per-call override replaces the constructor allowlist entirely, so a
        // host trusted globally is still rejected when the request scope excludes it.
        $validator = new UrlSafetyValidator(
            ['platform.example'],
            static fn (string $host): array => $host === 'platform.example' ? ['93.184.216.34'] : [],
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Profile host "platform.example" is not allowed.');

        $validator->validateAndResolve('https://platform.example/.well-known/ucp', ['other.example']);
    }

    /**
     * `PlatformPolicy::All` seen from the validator: the allowlist step is skipped, every
     * fetch-safety rule before it still runs.
     */
    #[Test]
    public function itAllowsAnyPublicHostWhenAskedToSkipTheAllowlist(): void
    {
        $validator = new UrlSafetyValidator([], static fn (string $host): array => ['93.184.216.34']);

        $validated = $validator->validateAndResolve('https://unknown.example/.well-known/ucp', null, true);

        self::assertSame('unknown.example', $validated->host);
    }

    #[Test]
    public function itStillRejectsBlockedAddressesWhenAskedToSkipTheAllowlist(): void
    {
        $validator = new UrlSafetyValidator([], static fn (string $host): array => ['10.0.0.8']);

        $this->expectException(ValidationException::class);

        $validator->validateAndResolve('https://internal.example/.well-known/ucp', null, true);
    }

    /**
     * Loopback skips the port and address rules for local development. "Any public host" must
     * not reopen that door in production: the server's own services stay unreachable.
     */
    #[Test]
    public function itStillRefusesLoopbackWhenAskedToSkipTheAllowlistOutsideDevelopmentMode(): void
    {
        $validator = new UrlSafetyValidator();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Profile host "127.0.0.1" is not allowed.');

        $validator->validateAndResolve('https://127.0.0.1:6379/.well-known/ucp', null, true);
    }

    #[Test]
    public function itAllowsLoopbackWhenAskedToSkipTheAllowlistInDevelopmentMode(): void
    {
        $validator = new UrlSafetyValidator([], null, true);

        $validated = $validator->validateAndResolve('https://localhost/.well-known/ucp', null, true);

        self::assertSame('localhost', $validated->host);
    }
}
