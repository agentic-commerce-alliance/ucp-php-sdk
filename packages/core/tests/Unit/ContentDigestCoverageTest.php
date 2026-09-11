<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Internal\Security\ContentDigestService;
use Ucp\Sdk\Internal\Security\DefaultSigningKeyManager;
use Ucp\Sdk\Internal\Security\EcdsaSignatureCodec;
use Ucp\Sdk\Internal\Security\Rfc9421RequestSignatureService;
use Ucp\Sdk\Model\Http\HttpRequest;
use Ucp\Sdk\Model\Security\ManagedSigningKey;

/**
 * Content-Digest is required exactly when it is covered, and a body must always be covered.
 *
 * It used to be required on every request unconditionally, including bodyless GETs, which have
 * no representation for it to describe (RFC 9530). A conformant peer sending
 * `GET /ucp/v1/orders/{id}` sends no digest, and was rejected -- which also meant the SDK's own
 * `GET /ucp/v1/catalog/product/{id}` route could never be signed successfully.
 *
 * Relaxing that has a security half, which is the part worth reviewing: if the digest is only
 * checked when covered, then omitting it from the covered list must not be a way to send an
 * unattested body.
 */
final class ContentDigestCoverageTest extends TestCase
{
    #[Test]
    public function itSignsABodylessRequestWithoutADigest(): void
    {
        $key = (new DefaultSigningKeyManager())->generate('kid-bodyless');
        $request = new HttpRequest('GET', 'https://merchant.example/ucp/v1/orders/order-1');

        $headers = (new Rfc9421RequestSignatureService(new ContentDigestService()))->sign($request, $key);

        self::assertArrayNotHasKey('Content-Digest', $headers);
        self::assertStringNotContainsString('content-digest', $headers['Signature-Input']);
        self::assertStringContainsString('("@method" "@target-uri")', $headers['Signature-Input']);
    }

    #[Test]
    public function itStillSignsABodyWithADigest(): void
    {
        $key = (new DefaultSigningKeyManager())->generate('kid-with-body');
        $request = new HttpRequest('POST', 'https://merchant.example/ucp/v1/carts', [], [], '{"a":1}');

        $headers = (new Rfc9421RequestSignatureService(new ContentDigestService()))->sign($request, $key);

        self::assertArrayHasKey('Content-Digest', $headers);
        self::assertStringContainsString('"content-digest"', $headers['Signature-Input']);
    }

    #[Test]
    public function itVerifiesItsOwnBodylessSignature(): void
    {
        $manager = new DefaultSigningKeyManager();
        $key = $manager->generate('kid-bodyless-round-trip');
        $request = new HttpRequest('GET', 'https://merchant.example/ucp/v1/orders/order-1');
        $service = new Rfc9421RequestSignatureService(new ContentDigestService());

        $headers = $service->sign($request, $key);
        $result = $service->verify(
            new HttpRequest($request->method, $request->absoluteUri, $headers),
            [$manager->toPublicKey($key)],
        );

        self::assertTrue($result->verified, (string) $result->failureReason);
        // Nothing was digested, so nothing was digest-verified: reporting otherwise would
        // overstate what the signature covered.
        self::assertFalse($result->contentDigestVerified);
    }

    /**
     * The attack the conditional check would otherwise open: drop content-digest from the
     * covered list, keep a body, and the signature over method and target still verifies while
     * nothing has attested to the bytes.
     */
    #[Test]
    public function itRefusesABodyThatTheSignatureDoesNotCover(): void
    {
        $manager = new DefaultSigningKeyManager();
        $key = $manager->generate('kid-uncovered-body');

        $request = $this->signedOver($key, ['@method', '@target-uri'], '{"amount":1}');

        $result = (new Rfc9421RequestSignatureService(new ContentDigestService()))
            ->verify($request, [$manager->toPublicKey($key)]);

        self::assertFalse($result->verified);
        self::assertSame(
            'Signature does not cover content-digest, so the request body is unattested.',
            $result->failureReason,
        );
    }

    #[Test]
    public function itRefusesACoveredDigestThatIsAbsentFromTheRequest(): void
    {
        $manager = new DefaultSigningKeyManager();
        $key = $manager->generate('kid-covered-but-absent');

        $request = $this->signedOver($key, ['@method', '@target-uri', 'content-digest'], '{"a":1}');
        $headers = $request->headers;
        unset($headers['Content-Digest']);

        $result = (new Rfc9421RequestSignatureService(new ContentDigestService()))
            ->verify(new HttpRequest($request->method, $request->absoluteUri, $headers, [], $request->body), [$manager->toPublicKey($key)]);

        self::assertFalse($result->verified);
        self::assertSame('Missing Content-Digest header.', $result->failureReason);
    }

    #[Test]
    public function itStillRefusesAWrongDigest(): void
    {
        $manager = new DefaultSigningKeyManager();
        $key = $manager->generate('kid-wrong-digest');
        $service = new Rfc9421RequestSignatureService(new ContentDigestService());
        $request = new HttpRequest('POST', 'https://merchant.example/ucp/v1/carts', [], [], '{"a":1}');

        $headers = $service->sign($request, $key);
        // Same body, digest of a different one.
        $headers['Content-Digest'] = (new ContentDigestService())->create('{"a":2}');

        $result = $service->verify(
            new HttpRequest($request->method, $request->absoluteUri, $headers, [], $request->body),
            [$manager->toPublicKey($key)],
        );

        self::assertFalse($result->verified);
        self::assertSame('Invalid Content-Digest header.', $result->failureReason);
    }

    /**
     * @param list<string> $components
     */
    private function signedOver(ManagedSigningKey $key, array $components, string $body): HttpRequest
    {
        $uri = 'https://merchant.example/ucp/v1/carts';
        $created = time();
        $digest = (new ContentDigestService())->create($body);
        $values = [
            '@method' => 'POST',
            '@target-uri' => $uri,
            'content-digest' => $digest,
        ];

        $params = sprintf(
            '(%s);created=%d;expires=%d;keyid="%s";alg="%s"',
            implode(' ', array_map(static fn (string $c): string => '"' . $c . '"', $components)),
            $created,
            $created + 120,
            $key->kid,
            'ecdsa-p256-sha256',
        );

        $lines = [];
        foreach ($components as $component) {
            $lines[] = sprintf('"%s": %s', $component, $values[$component]);
        }

        $lines[] = sprintf('"@signature-params": %s', $params);

        $der = '';
        self::assertTrue(openssl_sign(implode("\n", $lines), $der, $key->privateKeyPem, OPENSSL_ALGO_SHA256));

        return new HttpRequest('POST', $uri, [
            'Content-Digest' => $digest,
            'Signature-Input' => 'sig=' . $params,
            'Signature' => 'sig=:' . base64_encode((new EcdsaSignatureCodec())->derToRaw($der, 32)) . ':',
        ], [], $body);
    }
}
