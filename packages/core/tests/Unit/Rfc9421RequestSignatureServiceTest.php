<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Internal\Security\ContentDigestService;
use Ucp\Sdk\Internal\Security\DefaultSigningKeyManager;
use Ucp\Sdk\Internal\Security\Rfc9421RequestSignatureService;
use Ucp\Sdk\Model\Http\HttpRequest;
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
        $replayGuard = new class () implements SignatureReplayGuardInterface {
            public bool $called = false;

            public function rememberOrThrow(string $scope, string $kid, string $signature, ?int $created = null): void
            {
                $this->called = true;
            }
        };
        $service = new Rfc9421RequestSignatureService(new ContentDigestService(), $replayGuard);

        $signedHeaders = $service->sign($request, $managedKey, $created, $created + 120);
        $verifiedRequest = new HttpRequest($request->method, $request->absoluteUri, $signedHeaders, $request->query, $request->body);
        $result = $service->verify($verifiedRequest, [$manager->toPublicKey($managedKey)]);

        self::assertTrue($result->verified);
        self::assertSame('kid-1', $result->kid);
        self::assertTrue($result->contentDigestVerified);
        self::assertTrue($result->replayChecked);
        self::assertTrue($replayGuard->called);
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
