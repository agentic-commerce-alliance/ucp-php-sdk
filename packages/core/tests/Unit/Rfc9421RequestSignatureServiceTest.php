<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Exception\SignatureException;
use Ucp\Sdk\Internal\Security\ContentDigestService;
use Ucp\Sdk\Internal\Security\DefaultSigningKeyManager;
use Ucp\Sdk\Internal\Security\Rfc9421RequestSignatureService;
use Ucp\Sdk\Model\Http\HttpRequest;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Model\Security\ManagedSigningKey;
use Ucp\Sdk\Service\SignatureReplayGuardInterface;

final class Rfc9421RequestSignatureServiceTest extends TestCase
{
    #[Test]
    public function itSignsAndVerifiesRequests(): void
    {
        $manager = new DefaultSigningKeyManager();
        $managedKey = $manager->generate('kid-1');
        $request = new HttpRequest('post', 'https://merchant.example/ucp/v1/checkout-sessions', [], [], '{"ok":true}');
        $created = time();
        $replayGuard = $this->createMock(SignatureReplayGuardInterface::class);
        $replayGuard
            ->expects($this->once())
            ->method('rememberOrThrow');
        $service = new Rfc9421RequestSignatureService(new ContentDigestService(), $replayGuard);

        $signedHeaders = $service->sign($request, $managedKey, $created, $created + 120);
        $verifiedRequest = new HttpRequest($request->method, $request->absoluteUri, $signedHeaders, $request->query, $request->body);
        $result = $service->verify($verifiedRequest, [$manager->toPublicKey($managedKey)]);

        self::assertTrue($result->verified);
        self::assertSame('kid-1', $result->kid);
        self::assertTrue($result->contentDigestVerified);
        self::assertTrue($result->replayChecked);
    }

    #[Test]
    public function itVerifiesRequestsWithSigningKeysParsedFromDiscoveryProfileJson(): void
    {
        $manager = new DefaultSigningKeyManager();
        $managedKey = $manager->generate('kid-profile-round-trip');
        $profile = new PlatformProfile('2026-04-08', [], [], [], [$manager->toPublicKey($managedKey)]);
        $profilePayload = json_decode(json_encode($profile->toArray(), \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($profilePayload);
        $parsedProfile = PlatformProfile::fromArray($profilePayload);
        $request = new HttpRequest('post', 'https://merchant.example/ucp/v1/checkout-sessions', [], [], '{"ok":true}');
        $created = time();
        $service = new Rfc9421RequestSignatureService(new ContentDigestService());

        $signedHeaders = $service->sign($request, $managedKey, $created, $created + 120);
        $verifiedRequest = new HttpRequest($request->method, $request->absoluteUri, $signedHeaders, $request->query, $request->body);
        $result = $service->verify($verifiedRequest, $parsedProfile->signingKeys);

        self::assertTrue($result->verified, $result->failureReason ?? '');
        self::assertSame('kid-profile-round-trip', $result->kid);
    }

    #[Test]
    public function itVerifiesEs384RequestsWithSigningKeysParsedFromDiscoveryProfileJson(): void
    {
        $manager = new DefaultSigningKeyManager();
        $managedKey = $manager->generate('kid-profile-round-trip-es384', 'ES384');
        $profile = new PlatformProfile('2026-04-08', [], [], [], [$manager->toPublicKey($managedKey)]);
        $profilePayload = json_decode(json_encode($profile->toArray(), \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($profilePayload);
        $parsedProfile = PlatformProfile::fromArray($profilePayload);
        $request = new HttpRequest('post', 'https://merchant.example/ucp/v1/checkout-sessions', [], [], '{"ok":true}');
        $created = time();
        $service = new Rfc9421RequestSignatureService(new ContentDigestService());

        $signedHeaders = $service->sign($request, $managedKey, $created, $created + 120);
        $verifiedRequest = new HttpRequest($request->method, $request->absoluteUri, $signedHeaders, $request->query, $request->body);
        $result = $service->verify($verifiedRequest, $parsedProfile->signingKeys);

        self::assertTrue($result->verified, $result->failureReason ?? '');
        self::assertSame('kid-profile-round-trip-es384', $result->kid);
        self::assertSame('ES384', $result->algorithm);
    }

    #[Test]
    public function itEmbedsExplicitCreatedAndExpiresValuesWhenSigning(): void
    {
        $manager = new DefaultSigningKeyManager();
        $managedKey = $manager->generate('kid-explicit');
        $request = new HttpRequest('post', 'https://merchant.example/ucp/v1/checkout-sessions', [], [], '{"ok":true}');
        $service = new Rfc9421RequestSignatureService(new ContentDigestService(), maxLifetimeSeconds: 600);

        $signedHeaders = $service->sign($request, $managedKey, 1_700_000_000, 1_700_000_120);

        self::assertSame((new ContentDigestService())->create($request->body), $signedHeaders['Content-Digest']);
        self::assertStringContainsString('created=1700000000', $signedHeaders['Signature-Input']);
        self::assertStringContainsString('expires=1700000120', $signedHeaders['Signature-Input']);
        self::assertStringContainsString('keyid="kid-explicit"', $signedHeaders['Signature-Input']);
        self::assertStringContainsString('alg="ES256"', $signedHeaders['Signature-Input']);
        self::assertStringStartsWith('sig=:', $signedHeaders['Signature']);
    }

    #[Test]
    public function itDefaultsExpiresToCreatedPlusTheConfiguredLifetimeWhenSigning(): void
    {
        $manager = new DefaultSigningKeyManager();
        $managedKey = $manager->generate('kid-default-expiry');
        $request = new HttpRequest('post', 'https://merchant.example/ucp/v1/checkout-sessions', [], [], '{"ok":true}');
        $service = new Rfc9421RequestSignatureService(new ContentDigestService(), maxLifetimeSeconds: 420);

        $signedHeaders = $service->sign($request, $managedKey, 1_700_000_000);

        self::assertStringContainsString('created=1700000000', $signedHeaders['Signature-Input']);
        self::assertStringContainsString('expires=1700000420', $signedHeaders['Signature-Input']);
    }

    #[Test]
    public function itRejectsUnsupportedAlgorithmsWhenSigning(): void
    {
        $service = new Rfc9421RequestSignatureService(new ContentDigestService());
        $request = new HttpRequest('post', 'https://merchant.example/ucp/v1/checkout-sessions', [], [], '{"ok":true}');
        $unsupportedKey = new ManagedSigningKey('kid-unsupported', 'public', 'private', 'HS256');

        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('Unsupported signature algorithm "HS256".');

        $service->sign($request, $unsupportedKey, 1_700_000_000, 1_700_000_120);
    }

    #[Test]
    public function itRejectsDuplicateSigningKeys(): void
    {
        $manager = new DefaultSigningKeyManager();
        $managedKey = $manager->generate('kid-duplicate');
        $request = new HttpRequest('post', 'https://merchant.example/ucp/v1/checkout-sessions', [], [], '{"ok":true}');
        $created = time();
        $service = new Rfc9421RequestSignatureService(new ContentDigestService());

        $signedHeaders = $service->sign($request, $managedKey, $created, $created + 120);
        $verifiedRequest = new HttpRequest($request->method, $request->absoluteUri, $signedHeaders, $request->query, $request->body);
        $publicKey = $manager->toPublicKey($managedKey);

        $result = $service->verify($verifiedRequest, [$publicKey, $publicKey]);

        self::assertFalse($result->verified);
        self::assertSame('Duplicate signing keys found for kid.', $result->failureReason);
    }

    #[Test]
    public function itDoesNotMarkInvalidContentDigestAsVerified(): void
    {
        $manager = new DefaultSigningKeyManager();
        $managedKey = $manager->generate('kid-invalid-digest');
        $request = new HttpRequest('post', 'https://merchant.example/ucp/v1/checkout-sessions', [], [], '{"ok":true}');
        $created = time();
        $service = new Rfc9421RequestSignatureService(new ContentDigestService());

        $signedHeaders = $service->sign($request, $managedKey, $created, $created + 120);
        $signedHeaders['Content-Digest'] = 'sha-256=:invalid:';
        $result = $service->verify(
            new HttpRequest($request->method, $request->absoluteUri, $signedHeaders, $request->query, $request->body),
            [$manager->toPublicKey($managedKey)],
        );

        self::assertFalse($result->verified);
        self::assertSame('Invalid Content-Digest header.', $result->failureReason);
        self::assertFalse($result->contentDigestVerified);
    }

    #[Test]
    public function itMarksContentDigestAsVerifiedWhenOnlyTheSignatureFails(): void
    {
        $manager = new DefaultSigningKeyManager();
        $managedKey = $manager->generate('kid-invalid-signature');
        $request = new HttpRequest('post', 'https://merchant.example/ucp/v1/checkout-sessions', [], [], '{"ok":true}');
        $created = time();
        $service = new Rfc9421RequestSignatureService(new ContentDigestService());

        $signedHeaders = $service->sign($request, $managedKey, $created, $created + 120);
        $signedHeaders['Signature'] = 'sig=:' . base64_encode('invalid-signature') . ':';
        $result = $service->verify(
            new HttpRequest($request->method, $request->absoluteUri, $signedHeaders, $request->query, $request->body),
            [$manager->toPublicKey($managedKey)],
        );

        self::assertFalse($result->verified);
        self::assertSame('Request signature verification failed.', $result->failureReason);
        self::assertTrue($result->contentDigestVerified);
    }

    #[Test]
    public function itRejectsRequestsWhenSignatureHeadersAreIncomplete(): void
    {
        $manager = new DefaultSigningKeyManager();
        $managedKey = $manager->generate('kid-incomplete');
        $request = new HttpRequest('post', 'https://merchant.example/ucp/v1/checkout-sessions', [], [], '{"ok":true}');
        $created = time();
        $service = new Rfc9421RequestSignatureService(new ContentDigestService());
        $signedHeaders = $service->sign($request, $managedKey, $created, $created + 120);

        $resultWithoutInput = $service->verify(
            new HttpRequest($request->method, $request->absoluteUri, array_diff_key($signedHeaders, ['Signature-Input' => true]), $request->query, $request->body),
            [$manager->toPublicKey($managedKey)],
        );
        $resultWithoutSignature = $service->verify(
            new HttpRequest($request->method, $request->absoluteUri, array_diff_key($signedHeaders, ['Signature' => true]), $request->query, $request->body),
            [$manager->toPublicKey($managedKey)],
        );

        self::assertFalse($resultWithoutInput->verified);
        self::assertSame('Missing signature headers.', $resultWithoutInput->failureReason);
        self::assertFalse($resultWithoutSignature->verified);
        self::assertSame('Missing signature headers.', $resultWithoutSignature->failureReason);
    }

    #[Test]
    public function itRejectsMalformedSignatureInputsThatOmitRequiredFields(): void
    {
        $manager = new DefaultSigningKeyManager();
        $managedKey = $manager->generate('kid-required-fields');
        $request = new HttpRequest('post', 'https://merchant.example/ucp/v1/checkout-sessions', [], [], '{"ok":true}');
        $created = time();
        $service = new Rfc9421RequestSignatureService(new ContentDigestService());

        $signedHeaders = $service->sign($request, $managedKey, $created, $created + 120);
        $signedHeaders['Signature-Input'] = preg_replace('/;created=\d+/', '', $signedHeaders['Signature-Input']) ?? $signedHeaders['Signature-Input'];

        $result = $service->verify(
            new HttpRequest($request->method, $request->absoluteUri, $signedHeaders, $request->query, $request->body),
            [$manager->toPublicKey($managedKey)],
        );

        self::assertFalse($result->verified);
        self::assertSame('Signature-Input is missing required parameters.', $result->failureReason);
    }

    #[Test]
    public function itRejectsSignatureInputsWithUnsupportedAlgorithms(): void
    {
        $manager = new DefaultSigningKeyManager();
        $managedKey = $manager->generate('kid-unsupported-requested');
        $request = new HttpRequest('post', 'https://merchant.example/ucp/v1/checkout-sessions', [], [], '{"ok":true}');
        $created = time();
        $service = new Rfc9421RequestSignatureService(new ContentDigestService());

        $signedHeaders = $service->sign($request, $managedKey, $created, $created + 120);
        $signedHeaders['Signature-Input'] = str_replace('alg="ES256"', 'alg="HS256"', $signedHeaders['Signature-Input']);

        $result = $service->verify(
            new HttpRequest($request->method, $request->absoluteUri, $signedHeaders, $request->query, $request->body),
            [$manager->toPublicKey($managedKey)],
        );

        self::assertFalse($result->verified);
        self::assertSame('Unsupported signature algorithm "HS256".', $result->failureReason);
    }

    #[Test]
    public function itRejectsRequestsWhoseAdvertisedAlgorithmDoesNotMatchTheResolvedKey(): void
    {
        $manager = new DefaultSigningKeyManager();
        $managedKey = $manager->generate('kid-mismatch', 'ES256');
        $request = new HttpRequest('post', 'https://merchant.example/ucp/v1/checkout-sessions', [], [], '{"ok":true}');
        $created = time();
        $service = new Rfc9421RequestSignatureService(new ContentDigestService());

        $signedHeaders = $service->sign($request, $managedKey, $created, $created + 120);
        $signedHeaders['Signature-Input'] = str_replace('alg="ES256"', 'alg="ES384"', $signedHeaders['Signature-Input']);
        $verifiedRequest = new HttpRequest($request->method, $request->absoluteUri, $signedHeaders, $request->query, $request->body);

        $result = $service->verify($verifiedRequest, [$manager->toPublicKey($managedKey)]);

        self::assertFalse($result->verified);
        self::assertSame('Signature algorithm does not match signing key.', $result->failureReason);
    }

    #[Test]
    public function itRejectsSignaturesThatAreTooFarInTheFutureOrExpired(): void
    {
        $manager = new DefaultSigningKeyManager();
        $managedKey = $manager->generate('kid-time-window');
        $request = new HttpRequest('post', 'https://merchant.example/ucp/v1/checkout-sessions', [], [], '{"ok":true}');
        $service = new Rfc9421RequestSignatureService(new ContentDigestService());

        $futureCreated = time() + 120;
        $futureHeaders = $service->sign($request, $managedKey, $futureCreated, $futureCreated + 120);
        $futureResult = $service->verify(
            new HttpRequest($request->method, $request->absoluteUri, $futureHeaders, $request->query, $request->body),
            [$manager->toPublicKey($managedKey)],
        );

        $expiredCreated = time() - 500;
        $expiredHeaders = $service->sign($request, $managedKey, $expiredCreated, time() - 120);
        $expiredResult = $service->verify(
            new HttpRequest($request->method, $request->absoluteUri, $expiredHeaders, $request->query, $request->body),
            [$manager->toPublicKey($managedKey)],
        );

        self::assertFalse($futureResult->verified);
        self::assertSame('Signature created timestamp is too far in the future.', $futureResult->failureReason);
        self::assertFalse($expiredResult->verified);
        self::assertSame('Signature has expired.', $expiredResult->failureReason);
    }

    #[Test]
    public function itRejectsSignaturesWhoseLifetimeExceedsTheAllowedWindow(): void
    {
        $manager = new DefaultSigningKeyManager();
        $managedKey = $manager->generate('kid-lifetime');
        $request = new HttpRequest('post', 'https://merchant.example/ucp/v1/checkout-sessions', [], [], '{"ok":true}');
        $service = new Rfc9421RequestSignatureService(new ContentDigestService(), maxLifetimeSeconds: 300);

        $created = time();
        $signedHeaders = $service->sign($request, $managedKey, $created, $created + 301);
        $result = $service->verify(
            new HttpRequest($request->method, $request->absoluteUri, $signedHeaders, $request->query, $request->body),
            [$manager->toPublicKey($managedKey)],
        );

        self::assertFalse($result->verified);
        self::assertSame('Signature lifetime exceeds the allowed window.', $result->failureReason);
    }

    #[Test]
    public function itVerifiesRequestsSignedWithANonDefaultSignatureLabel(): void
    {
        $manager = new DefaultSigningKeyManager();
        $managedKey = $manager->generate('kid-alt-label');
        $request = new HttpRequest('post', 'https://merchant.example/ucp/v1/checkout-sessions', [], [], '{"ok":true}');
        $created = time();
        $service = new Rfc9421RequestSignatureService(new ContentDigestService());

        $signedHeaders = $service->sign($request, $managedKey, $created, $created + 120);
        $signedHeaders['Signature-Input'] = str_replace('sig=(', 'sig1=(', $signedHeaders['Signature-Input']);
        $signedHeaders['Signature'] = str_replace('sig=:', 'sig1=:', $signedHeaders['Signature']);
        $verifiedRequest = new HttpRequest($request->method, $request->absoluteUri, $signedHeaders, $request->query, $request->body);

        $result = $service->verify($verifiedRequest, [$manager->toPublicKey($managedKey)]);

        self::assertTrue($result->verified);
        self::assertSame('kid-alt-label', $result->kid);
    }
}
