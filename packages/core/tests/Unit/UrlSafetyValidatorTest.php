<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

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
            static fn (string $host): array => $host === 'platform.example' ? ['203.0.113.10'] : [],
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
            static fn (string $host): array => $host === 'platform.example' ? ['203.0.113.10'] : [],
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Profile host "platform.example" is not allowed.');

        $validator->validateAndResolve('https://platform.example/.well-known/ucp', ['other.example']);
    }
}
